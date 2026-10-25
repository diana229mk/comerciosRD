<?php

namespace App\Services;

use App\Models\ElectronicInvoice;
use App\Support\EcfFeature;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Envía e-CF a los endpoints web service de la DGII (sandbox / producción).
 *
 * Fases del flujo real DGII:
 *   1. Autenticación → obtiene semilla (seed) desde /Autenticacion/Semilla.
 *   2. Firmar la semilla con certificado digital tributario.
 *   3. Cambiar semilla firmada por token JWT en /Autenticacion/Api/ValidarSemilla.
 *   4. Firmar el XML del e-CF (XAdES, digestión SHA-256) con el mismo cert.
 *   5. Enviar el XML firmado a /Recepcion/Api/Recepcion.
 *   6. Poll a /ConsultaEstado/Api/EstadoTrackId?trackId=... para trackId.
 *   7. Registrar respuesta final (Aceptado/Rechazado + track_id + código).
 *
 * Este servicio deja los ganchos preparados; la firma XAdES real requiere
 * un certificado válido emitido por la DGII y se activa poblando en config:
 *   dgii.cert_path / dgii.cert_password / dgii.environment (sandbox|production).
 */
class DgiiSubmissionService
{
    protected string $env;
    protected string $baseUrl;
    protected int $timeout;

    public function __construct()
    {
        $this->env = (string) config('services.dgii.environment', env('DGII_ENV', 'sandbox'));
        $this->baseUrl = (string) config('services.dgii.base_url', $this->defaultBaseUrl());
        $this->timeout = (int) config('services.dgii.timeout', 30);
    }

    protected function defaultBaseUrl(): string
    {
        return match ($this->env) {
            'production' => 'https://ecf.dgii.gov.do',
            'certification' => 'https://ecf.dgii.gov.do/CerteCF',
            default => 'https://ecf.dgii.gov.do/testecf',
        };
    }

    /**
     * Firma el XML con XAdES (placeholder). En producción usa el cert real.
     */
    public function signXml(string $xml, ?string $certPath = null, ?string $certPassword = null): string
    {
        EcfFeature::ensureEnabled();

        $certPath = $certPath ?? (string) config('services.dgii.cert_path', '');
        $certPassword = $certPassword ?? (string) config('services.dgii.cert_password', '');

        if ($certPath === '' || ! is_file($certPath)) {
            // Modo sandbox sin cert: retorna XML "firmado" con nota interna.
            // NO usar en producción.
            return $this->addSignaturePlaceholder($xml);
        }

        // En producción real: usar robrichards/xmlseclibs o firmador propio.
        // Ejemplo (pseudocódigo):
        //   $pkcs12 = file_get_contents($certPath);
        //   openssl_pkcs12_read($pkcs12, $certs, $certPassword);
        //   $doc = new DOMDocument(); $doc->loadXML($xml);
        //   $signer = new XMLSecurityDSig(); ... etc.
        //
        // Por ahora, dejamos placeholder y logueamos.
        Log::info('DGII: firma XAdES pendiente de implementar con certificado real.');
        return $this->addSignaturePlaceholder($xml);
    }

    protected function addSignaturePlaceholder(string $xml): string
    {
        $stamp = '<!-- Firma XAdES pendiente: env='.$this->env.' at '.now()->toIso8601String().' -->';
        return str_replace('</ECF>', $stamp.'</ECF>', $xml);
    }

    /**
     * Autentica y obtiene token JWT. Retorna null si no hay credenciales.
     */
    public function authenticate(): ?string
    {
        EcfFeature::ensureEnabled();

        try {
            $seedResponse = Http::timeout($this->timeout)
                ->get($this->baseUrl.'/Autenticacion/Api/Semilla');

            if (! $seedResponse->successful()) {
                Log::warning('DGII Semilla falló', ['status' => $seedResponse->status()]);
                return null;
            }

            $seed = $seedResponse->body();
            $signedSeed = $this->signXml($seed);

            $tokenResponse = Http::timeout($this->timeout)
                ->attach('xml', $signedSeed, 'seed.xml')
                ->post($this->baseUrl.'/Autenticacion/Api/ValidarSemilla');

            if (! $tokenResponse->successful()) {
                Log::warning('DGII ValidarSemilla falló', ['status' => $tokenResponse->status()]);
                return null;
            }

            $data = $tokenResponse->json();
            return $data['token'] ?? null;
        } catch (ConnectionException $e) {
            Log::warning('DGII auth: no conexión', ['msg' => $e->getMessage()]);
            return null;
        } catch (\Throwable $e) {
            Log::error('DGII auth error', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Envía e-CF firmado al endpoint de recepción.
     */
    public function submit(ElectronicInvoice $invoice): array
    {
        EcfFeature::ensureEnabled();

        if (! $invoice->xml_content) {
            throw new Exception('El e-CF no tiene XML generado.');
        }

        // 1. Firmar XML
        $signedXml = $this->signXml($invoice->xml_content);
        $signedPath = 'ecf/'.$invoice->business_id.'/signed/'.$invoice->ecf_number.'.xml';
        Storage::disk('private')->put($signedPath, $signedXml);

        // 2. Autenticar
        $token = $this->authenticate();

        // 3. Registrar intento
        $invoice->forceFill([
            'signed_xml_path' => $signedPath,
            'submission_environment' => $this->env,
            'last_submission_at' => now(),
            'submission_attempts' => ($invoice->submission_attempts ?? 0) + 1,
        ])->saveQuietly();

        if (! $token) {
            return [
                'ok' => false,
                'message' => 'No se pudo autenticar con DGII '.$this->env.' (verifica credenciales/red).',
                'environment' => $this->env,
                'signed_xml_path' => $signedPath,
            ];
        }

        // 4. Enviar
        try {
            $response = Http::withToken($token)
                ->timeout($this->timeout)
                ->attach('xml', $signedXml, $invoice->ecf_number.'.xml')
                ->post($this->baseUrl.'/Recepcion/Api/Recepcion');

            $body = $response->json();
            $trackId = $body['trackId'] ?? $body['TrackId'] ?? null;
            $ok = $response->successful() && $trackId;

            $invoice->forceFill([
                'track_id' => $trackId ?? $invoice->track_id,
                'status' => $ok ? 'enviado' : 'error_envio',
                'dgii_response' => $body,
                'sent_at' => $ok ? now() : $invoice->sent_at,
            ])->saveQuietly();

            return [
                'ok' => $ok,
                'track_id' => $trackId,
                'status' => $response->status(),
                'response' => $body,
                'environment' => $this->env,
                'signed_xml_path' => $signedPath,
            ];
        } catch (\Throwable $e) {
            Log::error('DGII submit failed', ['ecf' => $invoice->ecf_number, 'msg' => $e->getMessage()]);
            $invoice->forceFill([
                'status' => 'error_envio',
                'dgii_response' => ['error' => $e->getMessage()],
            ])->saveQuietly();

            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'environment' => $this->env,
                'signed_xml_path' => $signedPath,
            ];
        }
    }

    /**
     * Consulta el estado del e-CF por trackId.
     */
    public function checkStatus(string $trackId): array
    {
        EcfFeature::ensureEnabled();

        $token = $this->authenticate();
        if (! $token) {
            return ['ok' => false, 'message' => 'No se pudo autenticar'];
        }
        try {
            $response = Http::withToken($token)
                ->timeout($this->timeout)
                ->get($this->baseUrl.'/ConsultaEstado/Api/Estado', ['trackId' => $trackId]);

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'response' => $response->json(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
