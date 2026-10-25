<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Order;
use App\Models\Sale;
use App\Services\TenantDocumentAuthorizer;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ReceiptController extends Controller
{
    /**
     * Descarga PDF del ticket de una venta.
     */
    public function salePdf(string $tenant, Sale $sale, TenantDocumentAuthorizer $authorizer): Response
    {
        $business = $authorizer->authorizeTenant($tenant, 'view_sales');
        $authorizer->assertRecordBelongsToBusiness($sale, $business);
        $sale->loadMissing(['items', 'customer', 'creator', 'cashier', 'business']);

        $payload = $this->salePayload($sale);
        $pdf = Pdf::loadView('pdf.receipt', $payload)
            ->setPaper([0, 0, 226.77, 600], 'portrait'); // ~80mm width

        $filename = 'ticket-'.($sale->sale_number ?: $sale->number).'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Vista HTML lista para imprimir (Ctrl+P).
     */
    public function salePrint(string $tenant, Sale $sale, TenantDocumentAuthorizer $authorizer): View
    {
        $business = $authorizer->authorizeTenant($tenant, 'view_sales');
        $authorizer->assertRecordBelongsToBusiness($sale, $business);
        $sale->loadMissing(['items', 'customer', 'creator', 'cashier', 'business']);

        $payload = $this->salePayload($sale);
        $payload['pdfUrl'] = route('receipts.sales.pdf', [
            'tenant' => $tenant,
            'sale' => $sale,
        ]);

        return view('pdf.receipt-print', $payload);
    }

    public function orderPdf(string $tenant, Order $order, TenantDocumentAuthorizer $authorizer): Response
    {
        $business = $authorizer->authorizeTenant($tenant, 'view_orders');
        $authorizer->assertRecordBelongsToBusiness($order, $business);
        $order->loadMissing(['items', 'customer', 'creator', 'business']);

        $payload = $this->orderPayload($order);
        $view = $this->isQuoteOrder($order) ? 'pdf.quote-order' : 'pdf.receipt';
        $pdf = Pdf::loadView($view, $payload)
            ->setPaper($this->isQuoteOrder($order) ? 'letter' : [0, 0, 226.77, 600], $this->isQuoteOrder($order) ? 'portrait' : 'portrait');

        $filename = ($this->isQuoteOrder($order) ? ($order->quote_number ?: $order->order_number) : 'ticket-'.$order->order_number).'.pdf';

        return $pdf->download($filename);
    }

    public function orderPrint(string $tenant, Order $order, TenantDocumentAuthorizer $authorizer): View
    {
        $business = $authorizer->authorizeTenant($tenant, 'view_orders');
        $authorizer->assertRecordBelongsToBusiness($order, $business);
        $order->loadMissing(['items', 'customer', 'creator', 'business']);

        $payload = $this->orderPayload($order);
        $payload['pdfUrl'] = route('receipts.orders.pdf', [
            'tenant' => $tenant,
            'order' => $order,
        ]);

        if ($this->isQuoteOrder($order)) {
            return view('pdf.quote-order', $payload);
        }

        return view('pdf.receipt-print', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    protected function salePayload(Sale $sale): array
    {
        $business = $sale->business
            ?? \App\Models\Business::query()->find($sale->business_id);

        $payment = $sale->payment_method;
        $paymentLabel = is_object($payment) && method_exists($payment, 'label')
            ? $payment->label()
            : (string) $payment;

        return [
            'business' => $business,
            'title' => 'TICKET DE VENTA',
            'documentNumber' => $sale->sale_number ?: $sale->number,
            'date' => $sale->sale_date ?: $sale->sold_at ?: $sale->created_at,
            'customerName' => $sale->customer_name ?: $sale->customer?->name,
            'cashierName' => $sale->creator?->name ?: $sale->cashier?->name,
            'paymentMethod' => $paymentLabel,
            'items' => $sale->items,
            'subtotal' => $sale->subtotal,
            'tax' => $sale->tax,
            'discount' => $sale->discount,
            'total' => $sale->total,
            'amountPaid' => $sale->amount_paid,
            'change' => $sale->change ?? $sale->change_due,
            'notes' => $sale->notes,
            // compat vista base
            'sale' => $sale,
            'order' => null,
            // batches mapping: sale_item_id => array of batches (code, expires_at)
            'batchesMap' => $sale->batchAllocations()->with('batch')->get()
                ->groupBy('sale_item_id')
                ->map(fn ($rows) => $rows->map(function ($alloc) {
                    $batch = $alloc->batch;
                    return [
                        'code' => $batch?->code,
                        'expires_at' => $batch?->expires_at ? \Illuminate\Support\Carbon::parse($batch->expires_at)->toDateString() : null,
                        'quantity' => $alloc->quantity,
                    ];
                })->values()->all())
                ->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderPayload(Order $order): array
    {
        $business = $order->business
            ?? \App\Models\Business::query()->find($order->business_id);
        $isQuote = $this->isQuoteOrder($order);

        return [
            'business' => $business,
            'title' => $isQuote ? 'COTIZACIÓN' : 'TICKET DE ORDEN',
            'documentNumber' => $isQuote ? ($order->quote_number ?: $order->order_number) : $order->order_number,
            'date' => $order->completed_at ?: $order->created_at,
            'customerName' => $order->customer_name ?: $order->customer?->name,
            'cashierName' => $order->creator?->name,
            'paymentMethod' => null,
            'items' => $order->items,
            'subtotal' => $order->subtotal,
            'tax' => $order->tax,
            'discount' => 0,
            'total' => $order->total,
            'notes' => $order->notes,
            'sale' => null,
            'order' => $order,
            'isQuote' => $isQuote,
        ];
    }

    protected function isQuoteOrder(Order $order): bool
    {
        return ! empty($order->quote_number) || ($order->business?->business_type === 'repuestos');
    }

    public function printTicket(string $sale, TenantDocumentAuthorizer $authorizer): Response
    {
        $saleModel = Sale::withoutBusinessScope()
            ->with(['items', 'customer', 'creator', 'cashier', 'business'])
            ->findOrFail($sale);

        $tenantId = null;
        try {
            $tenantId = Filament::getTenant()?->getKey();
        } catch (\Throwable) {
        }

        $tenantId = $tenantId
            ?? session('current_business_id')
            ?? auth()->user()?->current_business_id;
        $business = Business::query()->whereKey($tenantId)->where('is_active', true)->firstOrFail();
        $authorizer->authorizeBusiness($business, 'view_sales');
        $authorizer->assertRecordBelongsToBusiness($saleModel, $business);

        $pdf = Pdf::loadView('pdf.receipt', [
            'sale' => $saleModel,
            'business' => $saleModel->business,
        ]);
        $pdf->setPaper([0, 0, 226.77, 841.89], 'portrait');

        $filename = 'ticket-'.($saleModel->sale_number ?: $saleModel->number).'.pdf';

        return $pdf->stream($filename);
    }
}
