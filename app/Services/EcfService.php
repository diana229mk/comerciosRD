<?php

namespace App\Services;

use App\Enums\EcfType;
use App\Enums\ElectronicInvoiceStatus;
use App\Models\Business;
use App\Models\EcfSequence;
use App\Models\EcfSetting;
use App\Models\ElectronicInvoice;
use App\Models\Sale;
use App\Support\EcfFeature;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Numeración y emisión interna de e-CF (sin envío a DGII todavía).
 */
class EcfService
{
    /**
     * Obtiene el siguiente número disponible para un tipo de e-CF.
     *
     * @return array{sequence: EcfSequence, number: int, ecf_number: string}
     */
    public static function getNextNumber(Business $business, EcfType $type): array
    {
        EcfFeature::ensureEnabled();

        /** @var EcfSequence|null $sequence */
        $sequence = EcfSequence::withoutBusinessScope()
            ->where('business_id', $business->getKey())
            ->where('ecf_type', $type->value)
            ->where('is_active', true)
            ->whereColumn('current_number', '<', 'to_number')
            ->orderBy('from_number')
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            throw new Exception(
                "No hay secuencia activa disponible para el tipo e-CF {$type->value} ({$type->label()}). "
                .'Configura un rango en Secuencias Fiscales.'
            );
        }

        if (! $sequence->isValidOn()) {
            throw new Exception(
                "La secuencia del tipo e-CF {$type->value} está fuera de vigencia."
            );
        }

        $nextNumber = (int) $sequence->current_number + 1;

        if ($nextNumber < (int) $sequence->from_number) {
            $nextNumber = (int) $sequence->from_number;
        }

        if ($nextNumber > (int) $sequence->to_number) {
            throw new Exception("Se agotó el rango de números para el tipo e-CF {$type->value}.");
        }

        return [
            'sequence' => $sequence,
            'number' => $nextNumber,
            'ecf_number' => self::formatEcfNumber($type, $nextNumber, $sequence->series),
        ];
    }

    /**
     * Formatea el número del comprobante.
     * Ejemplo: E320000000001
     */
    public static function formatEcfNumber(EcfType $type, int $number, ?string $series = null): string
    {
        // Formato base DGII-friendly: E + tipo + 10 dígitos
        // (series se puede incorporar cuando se defina el estándar exacto del negocio)
        return 'E'.$type->value.str_pad((string) $number, 10, '0', STR_PAD_LEFT);
    }

    /**
     * Configuración e-CF del negocio (crea registro por defecto si no existe).
     */
    public static function settingsFor(Business|string $business): EcfSetting
    {
        return EcfSetting::forBusiness($business);
    }

    /**
     * Tipo e-CF por defecto configurado en el negocio.
     */
    public static function defaultTypeFor(Business $business): EcfType
    {
        $settings = self::settingsFor($business);
        $value = (string) ($settings->default_ecf_type ?: EcfType::CONSUMO->value);

        return EcfType::tryFrom($value) ?? EcfType::CONSUMO;
    }

    /**
     * Crea un e-CF a partir de una venta (emitido internamente, aún no enviado a DGII).
     */
    public static function createFromSale(Sale $sale, ?EcfType $type = null): ElectronicInvoice
    {
        EcfFeature::ensureEnabled();

        $sale->loadMissing(['items.product', 'customer', 'business']);

        $business = $sale->business;
        if (! $business instanceof Business) {
            $business = Business::query()->find($sale->business_id);
        }

        if (! $business) {
            throw new Exception('La venta no tiene negocio asociado.');
        }

        if (empty($business->rnc)) {
            throw new Exception('El negocio no tiene RNC configurado. Ve a Configuración e-CF o Datos del Negocio.');
        }

        $settings = self::settingsFor($business);
        $type ??= self::defaultTypeFor($business);

        // Crédito fiscal: exigir RNC del comprador si está configurado
        if (
            $type === EcfType::CREDITO_FISCAL
            && $settings->require_buyer_rnc_for_31
        ) {
            $customer = $sale->customer;
            $buyerRnc = preg_replace('/\D/', '', (string) ($customer?->rnc ?? $customer?->document ?? '')) ?: '';
            if (strlen($buyerRnc) < 9) {
                throw new Exception(
                    'El e-CF 31 (Crédito Fiscal) requiere RNC del comprador. '
                    .'Asigna un cliente con RNC o emite tipo 32 (Consumo).'
                );
            }
        }

        // Evitar duplicar e-CF para la misma venta + tipo
        $existing = ElectronicInvoice::withoutBusinessScope()
            ->where('business_id', $business->getKey())
            ->where('sale_id', $sale->getKey())
            ->where('ecf_type', $type->value)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($sale, $business, $type) {
            $next = self::getNextNumber($business, $type);
            /** @var EcfSequence $sequence */
            $sequence = $next['sequence'];

            // Actualizar contador de la secuencia
            $sequence->forceFill(['current_number' => $next['number']])->save();

            $customer = $sale->customer;
            $buyerName = $customer?->name
                ?: ($sale->customer_name ?: 'Cliente de Consumo');
            $buyerRnc = $customer?->rnc ?? $customer?->document ?? null;

            $invoice = new ElectronicInvoice([
                'sale_id' => $sale->id,
                'customer_id' => $customer?->id,
                'ecf_type' => $type,
                'ecf_number' => $next['ecf_number'],
                'sequence_number' => $next['number'],
                'issuer_rnc' => $business->rnc,
                'issuer_name' => $business->name,
                'buyer_rnc' => $buyerRnc,
                'buyer_name' => $buyerName,
                'buyer_email' => $customer?->email,
                'subtotal' => $sale->subtotal,
                'discount' => $sale->discount ?? 0,
                'tax' => $sale->tax,
                'total' => $sale->total,
                'status' => ElectronicInvoiceStatus::Issued, // Emitido internamente
                'issue_date' => $sale->sale_date?->toDateString()
                    ?? $sale->sold_at?->toDateString()
                    ?? now()->toDateString(),
                'created_by' => $sale->created_by ?? auth()->id(),
            ]);
            $invoice->business_id = $business->getKey();
            $invoice->save();

            $taxRate = (float) ($sale->tax_rate ?? 18);
            if ($taxRate <= 0) {
                $taxRate = 18;
            }

            foreach ($sale->items as $item) {
                $qty = (float) $item->quantity;
                $unitPrice = (float) $item->unit_price;
                $lineSubtotal = round((float) ($item->subtotal ?? ($qty * $unitPrice)), 2);
                // Prorrateo simple de ITBIS de la venta si hay tax_rate
                $lineTax = round($lineSubtotal * ($taxRate / 100), 2);

                $invoice->items()->create([
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name ?: ($item->product?->name ?? 'Producto'),
                    'product_code' => $item->product?->barcode
                        ?? $item->product?->sku
                        ?? $item->sku
                        ?? null,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'discount' => 0,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $lineTax,
                    'subtotal' => $lineSubtotal,
                    'total' => round($lineSubtotal + $lineTax, 2),
                ]);
            }

            // Generar y guardar el XML (sin firma digital todavía)
            $invoice->load(['items', 'business', 'sale']);
            $xmlContent = EcfXmlGenerator::generate($invoice);
            $invoice->forceFill(['xml_content' => $xmlContent])->saveQuietly();

            // Generar PDF de representación e intentar guardarlo (no bloquea emisión)
            try {
                $pdf = self::generatePdf($invoice);
                $pdfPath = 'ecf/'.$invoice->business_id.'/'.$invoice->ecf_number.'.pdf';
                Storage::disk('private')->put($pdfPath, $pdf->output());
                $invoice->forceFill(['pdf_path' => $pdfPath])->saveQuietly();
            } catch (\Throwable $e) {
                // No bloquear la emisión si falla el PDF
            }

            return $invoice->load(['items', 'customer', 'sale']);
        });
    }

    /**
     * Emite una Nota de Crédito Electrónica (e-CF 34) por la devolución de una venta.
     *
     * @param  Sale                            $sale             Venta original.
     * @param  array<string, float|int|string> $allocationQuantities  [sale_item_batch_id => qty devuelta]
     * @param  int                             $modificationCode 1=Anulación · 2=Corrección · 3=Devolución · 4=Descuento
     * @param  string|null                     $reason           Motivo textual.
     */
    public static function createCreditNoteForReturn(
        Sale $sale,
        array $allocationQuantities,
        int $modificationCode = 3,
        ?string $reason = null,
    ): ElectronicInvoice {
        EcfFeature::ensureEnabled();

        $sale->loadMissing(['items.product', 'customer', 'business']);

        $business = $sale->business ?: Business::query()->find($sale->business_id);
        if (! $business instanceof Business) {
            throw new Exception('La venta no tiene negocio asociado.');
        }
        if (empty($business->rnc)) {
            throw new Exception('El negocio no tiene RNC configurado; no se puede emitir Nota de Crédito.');
        }

        // Busca el e-CF original de la venta (prioriza aceptado, luego cualquiera no borrado).
        $original = ElectronicInvoice::withoutBusinessScope()
            ->where('business_id', $business->getKey())
            ->where('sale_id', $sale->getKey())
            ->whereNull('deleted_at')
            ->whereIn('ecf_type', [
                EcfType::CREDITO_FISCAL->value,
                EcfType::CONSUMO->value,
            ])
            ->orderByDesc('created_at')
            ->first();

        if (! $original) {
            throw new Exception(
                'La venta no tiene e-CF original emitido; no se puede emitir Nota de Crédito. '
                .'Emite primero el e-CF de la venta.'
            );
        }

        // Consolida cantidades a devolver por sale_item (agregando lotes)
        /** @var \Illuminate\Support\Collection<int, \App\Models\SaleItemBatch> $allocations */
        $allocations = \App\Models\SaleItemBatch::withoutBusinessScope()
            ->where('business_id', $business->getKey())
            ->where('sale_id', $sale->getKey())
            ->whereIn('id', array_keys($allocationQuantities))
            ->with(['saleItem.product'])
            ->get();

        $qtyPerSaleItem = [];  // sale_item_id => qty total devuelta
        foreach ($allocations as $alloc) {
            $key = (string) $alloc->sale_item_id;
            $qty = (float) ($allocationQuantities[(string) $alloc->id] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $qtyPerSaleItem[$key] = ($qtyPerSaleItem[$key] ?? 0.0) + $qty;
        }

        if ($qtyPerSaleItem === []) {
            throw new Exception('No se recibieron cantidades válidas para la Nota de Crédito.');
        }

        return DB::transaction(function () use (
            $sale, $business, $original, $qtyPerSaleItem, $modificationCode, $reason
        ) {
            // Reservar próximo número tipo 34
            $next = self::getNextNumber($business, EcfType::NOTA_CREDITO);
            /** @var EcfSequence $sequence */
            $sequence = $next['sequence'];
            $sequence->forceFill(['current_number' => $next['number']])->save();

            $taxRate = (float) ($sale->tax_rate ?? 18);
            if ($taxRate <= 0) {
                $taxRate = 18;
            }

            // Prepara líneas de la NC + totales
            $lines = [];
            $subtotal = 0.0;
            $tax = 0.0;

            foreach ($qtyPerSaleItem as $saleItemId => $qty) {
                $item = $sale->items->firstWhere('id', $saleItemId);
                if (! $item) {
                    continue;
                }

                $unitPrice = (float) $item->unit_price;
                $lineSubtotal = round($unitPrice * (float) $qty, 2);
                $lineTax = round($lineSubtotal * ($taxRate / 100), 2);

                $subtotal += $lineSubtotal;
                $tax += $lineTax;

                $lines[] = [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name ?: ($item->product?->name ?? 'Producto'),
                    'product_code' => $item->product?->barcode ?? $item->product?->sku ?? $item->sku,
                    'quantity' => (float) $qty,
                    'unit_price' => $unitPrice,
                    'discount' => 0,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $lineTax,
                    'subtotal' => $lineSubtotal,
                    'total' => round($lineSubtotal + $lineTax, 2),
                ];
            }

            if ($lines === []) {
                throw new Exception('No hay líneas devueltas válidas para la Nota de Crédito.');
            }

            $subtotal = round($subtotal, 2);
            $tax = round($tax, 2);
            $total = round($subtotal + $tax, 2);

            $invoice = new ElectronicInvoice([
                'sale_id' => $sale->id,
                'references_invoice_id' => $original->id,
                'references_ecf_number' => $original->ecf_number,
                'modification_code' => $modificationCode,
                'modification_reason' => $reason
                    ?: 'Devolución de mercancía sobre venta '.($sale->sale_number ?? ''),
                'customer_id' => $original->customer_id,
                'ecf_type' => EcfType::NOTA_CREDITO,
                'ecf_number' => $next['ecf_number'],
                'sequence_number' => $next['number'],
                'issuer_rnc' => $business->rnc,
                'issuer_name' => $business->name,
                'buyer_rnc' => $original->buyer_rnc,
                'buyer_name' => $original->buyer_name,
                'buyer_email' => $original->buyer_email,
                'subtotal' => $subtotal,
                'discount' => 0,
                'tax' => $tax,
                'total' => $total,
                'status' => ElectronicInvoiceStatus::Issued,
                'issue_date' => now()->toDateString(),
                'created_by' => auth()->id() ?? $sale->created_by,
            ]);
            $invoice->business_id = $business->getKey();
            $invoice->save();

            foreach ($lines as $l) {
                $invoice->items()->create($l);
            }

            // Generar XML de la NC (referencia se incluye si el generador lo lee del modelo)
            $invoice->load(['items', 'business', 'sale', 'referencedInvoice']);
            try {
                $xml = EcfXmlGenerator::generate($invoice);
                $invoice->forceFill(['xml_content' => $xml])->saveQuietly();
            } catch (\Throwable $e) {
                // No bloquear emisión si el XML falla.
            }

            // PDF
            try {
                $pdf = self::generatePdf($invoice);
                $pdfPath = 'ecf/'.$invoice->business_id.'/'.$invoice->ecf_number.'.pdf';
                Storage::disk('private')->put($pdfPath, $pdf->output());
                $invoice->forceFill(['pdf_path' => $pdfPath])->saveQuietly();
            } catch (\Throwable $e) {
                // No bloquear.
            }

            return $invoice->load(['items', 'customer', 'sale', 'referencedInvoice']);
        });
    }

    /**
     * Genera el PDF de representación del e-CF.
     */
    public static function generatePdf(ElectronicInvoice $invoice): \Barryvdh\DomPDF\PDF
    {
        EcfFeature::ensureEnabled();

        $invoice->loadMissing(['items', 'business']);

        // QR simple con el número e-CF (API pública, sin paquete extra)
        $qrBase64 = null;
        try {
            $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data='
                .urlencode((string) $invoice->ecf_number);
            $qrContent = Http::timeout(5)->get($qrUrl)->body();
            if ($qrContent !== '' && $qrContent !== false) {
                $qrBase64 = base64_encode($qrContent);
            }
        } catch (\Throwable $e) {
            // Si falla el QR, el PDF se genera igual sin él
        }

        return Pdf::loadView('pdf.ecf-representation', [
            'invoice' => $invoice,
            'qrBase64' => $qrBase64,
        ])->setPaper('letter', 'portrait');
    }

    /**
     * Regenera el XML de un comprobante ya emitido.
     */
    public static function regenerateXml(ElectronicInvoice $invoice): ElectronicInvoice
    {
        EcfFeature::ensureEnabled();

        return EcfXmlGenerator::generateAndStore($invoice);
    }
}
