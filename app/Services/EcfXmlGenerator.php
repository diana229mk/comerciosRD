<?php

namespace App\Services;

use App\Enums\EcfType;
use App\Enums\PaymentMethod;
use App\Models\ElectronicInvoice;
use App\Support\EcfFeature;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;

/**
 * Genera XML base de e-CF (sin firma digital).
 *
 * Alineado al formato DGII (tipos 31 y 32 principalmente).
 * Antes de certificación hay que validar contra el XSD oficial V1.0.
 */
class EcfXmlGenerator
{
    /**
     * Genera el XML del e-CF (sin firma digital todavía).
     */
    public static function generate(ElectronicInvoice $invoice): string
    {
        EcfFeature::ensureEnabled();

        $invoice->loadMissing(['items', 'sale', 'business']);

        $business = $invoice->business;
        $issueDate = Carbon::parse($invoice->issue_date ?? now());
        $now = now();

        $tipoeCF = $invoice->ecf_type instanceof EcfType
            ? $invoice->ecf_type->value
            : (string) $invoice->ecf_type;

        $encf = (string) $invoice->ecf_number;

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $root = $xml->createElement('ECF');
        // Namespace orientativo; el XSD oficial puede diferir en el URI exacto
        $root->setAttribute('xmlns', 'https://dgii.gov.do');
        $xml->appendChild($root);

        // ========== ENCABEZADO ==========
        $encabezado = $xml->createElement('Encabezado');
        $root->appendChild($encabezado);

        self::appendText($xml, $encabezado, 'Version', '1.0');

        // IdDoc
        $idDoc = $xml->createElement('IdDoc');
        $encabezado->appendChild($idDoc);

        self::appendText($xml, $idDoc, 'TipoeCF', $tipoeCF);
        self::appendText($xml, $idDoc, 'eNCF', $encf);
        self::appendText(
            $xml,
            $idDoc,
            'FechaVencimientoSecuencia',
            $issueDate->copy()->addYear()->format('d-m-Y')
        );
        $isCreditNote = $tipoeCF === EcfType::NOTA_CREDITO->value;
        self::appendText($xml, $idDoc, 'IndicadorNotaCredito', $isCreditNote ? '1' : '0');
        self::appendText($xml, $idDoc, 'IndicadorEnvioDiferido', '0');
        self::appendText($xml, $idDoc, 'IndicadorMontoGravado', '0');
        self::appendText($xml, $idDoc, 'TipoIngresos', '01'); // Operaciones
        self::appendText($xml, $idDoc, 'TipoPago', self::mapPaymentType($invoice));
        self::appendText($xml, $idDoc, 'FechaHoraFirma', $now->format('d-m-Y H:i:s'));

        // ========== EMISOR ==========
        $emisor = $xml->createElement('Emisor');
        $encabezado->appendChild($emisor);

        self::appendText($xml, $emisor, 'RNCEmisor', self::cleanRnc($invoice->issuer_rnc));
        self::appendText($xml, $emisor, 'RazonSocialEmisor', (string) $invoice->issuer_name);

        if ($business?->address) {
            self::appendText($xml, $emisor, 'DireccionEmisor', (string) $business->address);
        }
        if ($business?->phone) {
            self::appendText($xml, $emisor, 'TelefonoEmisor', (string) $business->phone);
        }

        self::appendText($xml, $emisor, 'FechaEmision', $issueDate->format('d-m-Y'));

        // ========== COMPRADOR ==========
        $comprador = $xml->createElement('Comprador');
        $encabezado->appendChild($comprador);

        $buyerRnc = self::cleanRnc($invoice->buyer_rnc ?? '');
        $buyerName = (string) ($invoice->buyer_name ?: 'CONSUMIDOR FINAL');

        if ($tipoeCF === EcfType::CREDITO_FISCAL->value && $buyerRnc && $buyerRnc !== '000000000') {
            self::appendText($xml, $comprador, 'RNCComprador', $buyerRnc);
            self::appendText($xml, $comprador, 'RazonSocialComprador', $buyerName);
        } else {
            if ($buyerRnc && $buyerRnc !== '000000000') {
                self::appendText($xml, $comprador, 'RNCComprador', $buyerRnc);
            }
            self::appendText($xml, $comprador, 'RazonSocialComprador', $buyerName);
        }

        // ========== TOTALES ==========
        $totales = $xml->createElement('Totales');
        $encabezado->appendChild($totales);

        $subtotal = (float) $invoice->subtotal;
        $tax = (float) $invoice->tax;
        $total = (float) $invoice->total;
        $discount = (float) ($invoice->discount ?? 0);

        self::appendText($xml, $totales, 'MontoGravadoTotal', self::money($subtotal));
        self::appendText($xml, $totales, 'MontoGravadoI1', self::money($subtotal)); // gravado 18%
        self::appendText($xml, $totales, 'MontoExento', '0.00');
        self::appendText($xml, $totales, 'ITBIS1', '18');
        self::appendText($xml, $totales, 'TotalITBIS', self::money($tax));
        self::appendText($xml, $totales, 'TotalITBIS1', self::money($tax));

        if ($discount > 0) {
            self::appendText($xml, $totales, 'TotalDescuento', self::money($discount));
        }

        self::appendText($xml, $totales, 'MontoTotal', self::money($total));

        // ========== DETALLES ==========
        $detalles = $xml->createElement('DetallesItems');
        $root->appendChild($detalles);

        $lineNumber = 1;
        foreach ($invoice->items as $item) {
            $itemNode = $xml->createElement('Item');
            $detalles->appendChild($itemNode);

            self::appendText($xml, $itemNode, 'NumeroLinea', (string) $lineNumber);
            self::appendText($xml, $itemNode, 'IndicadorFacturacion', '1'); // gravado 18%
            self::appendText($xml, $itemNode, 'NombreItem', (string) $item->product_name);

            if ($item->product_code) {
                self::appendText($xml, $itemNode, 'CodigoItem', (string) $item->product_code);
            }

            self::appendText($xml, $itemNode, 'IndicadorBienoServicio', '1'); // 1 = bien
            self::appendText($xml, $itemNode, 'CantidadItem', self::qty($item->quantity));
            self::appendText($xml, $itemNode, 'UnidadMedida', '43'); // unidad (catálogo DGII)
            self::appendText($xml, $itemNode, 'PrecioUnitarioItem', self::money($item->unit_price));
            self::appendText($xml, $itemNode, 'MontoItem', self::money($item->subtotal));

            $lineNumber++;
        }

        // ========== INFORMACIÓN DE REFERENCIA (Solo Notas de Crédito/Débito, tipo 33/34) ==========
        if (in_array($tipoeCF, [EcfType::NOTA_DEBITO->value, EcfType::NOTA_CREDITO->value], true)
            && $invoice->references_ecf_number) {
            $ref = $xml->createElement('InformacionReferencia');
            $root->appendChild($ref);

            self::appendText($xml, $ref, 'NCFModificado', (string) $invoice->references_ecf_number);

            $refDate = $invoice->referencedInvoice?->issue_date;
            if ($refDate) {
                self::appendText(
                    $xml,
                    $ref,
                    'FechaNCFModificado',
                    Carbon::parse($refDate)->format('d-m-Y')
                );
            }

            self::appendText(
                $xml,
                $ref,
                'CodigoModificacion',
                str_pad((string) ($invoice->modification_code ?? 3), 1, '0', STR_PAD_LEFT)
            );

            if ($invoice->modification_reason) {
                self::appendText($xml, $ref, 'RazonModificacion', (string) $invoice->modification_reason);
            }
        }

        // Placeholder de firma (la firma digital XAdES se agrega en fase posterior)
        self::appendText($xml, $root, 'FechaHoraFirma', $now->format('d-m-Y H:i:s'));

        $output = $xml->saveXML();

        return is_string($output) ? $output : '';
    }

    /**
     * Regenera y persiste el XML en el comprobante.
     */
    public static function generateAndStore(ElectronicInvoice $invoice): ElectronicInvoice
    {
        EcfFeature::ensureEnabled();

        $xml = self::generate($invoice);
        $invoice->forceFill(['xml_content' => $xml])->saveQuietly();

        return $invoice->fresh() ?? $invoice;
    }

    protected static function appendText(DOMDocument $xml, DOMElement $parent, string $name, string $value): void
    {
        $node = $xml->createElement($name);
        $node->appendChild($xml->createTextNode($value));
        $parent->appendChild($node);
    }

    protected static function cleanRnc(?string $rnc): string
    {
        $clean = preg_replace('/\D/', '', $rnc ?? '') ?: '';

        return $clean !== '' ? $clean : '000000000';
    }

    protected static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    protected static function qty(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    /**
     * TipoPago DGII: 1 = Contado, 2 = Crédito, 3 = Gratuito
     */
    protected static function mapPaymentType(ElectronicInvoice $invoice): string
    {
        $method = $invoice->sale?->payment_method;

        if ($method instanceof PaymentMethod) {
            if (in_array($method, [PaymentMethod::Credito, PaymentMethod::Credit], true)) {
                return '2';
            }

            return '1';
        }

        $value = strtolower((string) ($method ?? ''));

        return in_array($value, ['credito', 'credit'], true) ? '2' : '1';
    }
}
