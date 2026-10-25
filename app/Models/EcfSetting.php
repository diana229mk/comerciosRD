<?php

namespace App\Models;

use App\Casts\EncryptedOrPlain;
use App\Enums\EcfEnvironment;
use App\Enums\EcfType;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Configuración de Facturación Electrónica por negocio.
 * Un registro por business_id.
 */
class EcfSetting extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $fillable = [
        // business_id: trait
        'environment',
        'certificate_path',
        'certificate_password',
        'api_username',
        'api_password',
        'default_ecf_type',
        'auto_issue_on_sale',
        'require_buyer_rnc_for_31',
        'contact_email',
        'notes',
        'is_ready',
    ];

    protected function casts(): array
    {
        return [
            'environment' => EcfEnvironment::class,
            'certificate_password' => EncryptedOrPlain::class,
            'api_password' => EncryptedOrPlain::class,
            'auto_issue_on_sale' => 'boolean',
            'require_buyer_rnc_for_31' => 'boolean',
            'is_ready' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public static function forBusiness(Business|string $business): self
    {
        $businessId = $business instanceof Business
            ? (string) $business->getKey()
            : (string) $business;

        $setting = static::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->first();

        if ($setting) {
            return $setting;
        }

        $setting = new static([
            'environment' => EcfEnvironment::Test,
            'default_ecf_type' => EcfType::CONSUMO->value,
            'auto_issue_on_sale' => false,
            'require_buyer_rnc_for_31' => true,
            'is_ready' => false,
        ]);
        $setting->business_id = $businessId;
        $setting->save();

        return $setting;
    }

    public function hasCertificateFile(): bool
    {
        $path = $this->safeCertificatePath();
        if (! $path) {
            return false;
        }

        return Storage::disk('private')->exists($path)
            || Storage::disk('local')->exists($path)
            || Storage::disk('public')->exists($path);
    }

    public function certificateAbsolutePath(): ?string
    {
        $path = $this->safeCertificatePath();
        if (! $path) {
            return null;
        }

        if (Storage::disk('private')->exists($path) && config('filesystems.disks.private.driver') === 'local') {
            return Storage::disk('private')->path($path);
        }

        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->path($path);
        }

        return null;
    }

    private function safeCertificatePath(): ?string
    {
        if (! filled($this->certificate_path)) {
            return null;
        }

        $path = (string) $this->certificate_path;
        if (str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    /**
     * Checklist de preparación (sin validar aún la API DGII).
     *
     * @return array{ok: bool, checks: array<string, array{ok: bool, message: string}>}
     */
    public function readinessChecklist(?Business $business = null): array
    {
        $business ??= $this->business
            ?? Business::query()->find($this->business_id);

        $checks = [];

        $rnc = preg_replace('/\D/', '', (string) ($business?->rnc ?? '')) ?: '';
        $checks['rnc'] = [
            'ok' => strlen($rnc) >= 9,
            'message' => strlen($rnc) >= 9
                ? 'RNC del negocio configurado'
                : 'Falta el RNC en Datos del Negocio',
        ];

        $checks['name'] = [
            'ok' => filled($business?->name),
            'message' => filled($business?->name)
                ? 'Razón social / nombre del negocio OK'
                : 'Falta el nombre del negocio',
        ];

        $hasSeq = EcfSequence::withoutBusinessScope()
            ->where('business_id', $this->business_id)
            ->where('is_active', true)
            ->whereColumn('current_number', '<', 'to_number')
            ->exists();

        $checks['sequence'] = [
            'ok' => $hasSeq,
            'message' => $hasSeq
                ? 'Hay al menos una secuencia fiscal activa con números disponibles'
                : 'Configura una Secuencia Fiscal activa (ej. tipo 32)',
        ];

        $checks['certificate'] = [
            'ok' => $this->hasCertificateFile(),
            'message' => $this->hasCertificateFile()
                ? 'Certificado digital cargado'
                : 'Falta subir el certificado digital (.p12 / .pfx)',
        ];

        $checks['certificate_password'] = [
            'ok' => filled($this->certificate_password),
            'message' => filled($this->certificate_password)
                ? 'Contraseña del certificado guardada'
                : 'Falta la contraseña del certificado',
        ];

        $env = $this->environment instanceof EcfEnvironment
            ? $this->environment
            : EcfEnvironment::tryFrom((string) $this->environment);

        $checks['environment'] = [
            'ok' => $env !== null,
            'message' => $env
                ? 'Ambiente: '.$env->label()
                : 'Selecciona el ambiente DGII (Test / Cert / Producción)',
        ];

        // Listo para "emisión interna" (sin API) si RNC + secuencia
        // Listo para "envío DGII" (futuro) si además hay certificado
        $okInternal = $checks['rnc']['ok'] && $checks['name']['ok'] && $checks['sequence']['ok'];
        $okDgii = $okInternal && $checks['certificate']['ok'] && $checks['certificate_password']['ok'];

        return [
            'ok' => $okInternal,
            'ok_for_dgii' => $okDgii,
            'checks' => $checks,
        ];
    }

    public function refreshReadyFlag(?Business $business = null): void
    {
        $report = $this->readinessChecklist($business);
        $this->forceFill(['is_ready' => $report['ok']])->saveQuietly();
    }
}
