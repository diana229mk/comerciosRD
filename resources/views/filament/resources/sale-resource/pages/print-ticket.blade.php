<x-filament-panels::page>
    @php
        /** @var \App\Models\Sale $sale */
        $sale = $this->record;
        $tenant = filament()->getTenant();
        $business = $sale->business ?? $tenant;
        $payment = $sale->payment_method instanceof \App\Enums\PaymentMethod
            ? $sale->payment_method->value
            : (string) $sale->payment_method;
        $isCash = in_array($payment, ['efectivo', 'cash'], true);
        $tendered = $sale->amount_tendered ?? $sale->amount_paid ?? $sale->total;
        $change = $sale->change_amount ?? $sale->change ?? $sale->change_due ?? 0;
        $payLabel = $sale->payment_method instanceof \App\Enums\PaymentMethod
            ? $sale->payment_method->label()
            : ucfirst((string) $payment);
    @endphp

    <div class="max-w-sm mx-auto bg-white text-black p-4 rounded-xl shadow dark:bg-white" id="ticket">
        <div class="text-center border-b border-gray-300 pb-3 mb-3">
            <div class="font-bold text-lg">{{ $business?->name ?? $tenant?->name }}</div>
            @if($business?->rnc)
                <div class="text-sm">RNC: {{ $business->rnc }}</div>
            @endif
            @if($business?->address)
                <div class="text-xs text-gray-600">{{ $business->address }}</div>
            @endif
            <div class="text-sm mt-1 font-semibold">Ticket #{{ $sale->sale_number ?? $sale->id }}</div>
            <div class="text-xs text-gray-600">
                {{ $sale->sale_date?->format('d/m/Y H:i') ?? $sale->sold_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }}
            </div>
            @if($sale->customer_name || $sale->customer)
                <div class="text-xs mt-1">Cliente: {{ $sale->customer_name ?? $sale->customer?->name }}</div>
            @endif
        </div>

        <table class="w-full text-sm mb-3">
            <thead>
                <tr class="border-b border-gray-300">
                    <th class="text-left py-1">Producto</th>
                    <th class="text-right py-1">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sale->items as $item)
                    <tr>
                        <td class="py-1 align-top">
                            {{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}
                            x {{ $item->product_name }}
                            <div class="text-xs text-gray-500">
                                @ RD$ {{ number_format((float) $item->unit_price, 2) }}
                            </div>
                        </td>
                        <td class="text-right py-1 align-top">
                            RD$ {{ number_format((float) ($item->subtotal ?? $item->line_total ?? 0), 2) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="border-t border-gray-300 pt-2 text-sm space-y-1">
            <div class="flex justify-between">
                <span>Subtotal</span>
                <span>RD$ {{ number_format((float) $sale->subtotal, 2) }}</span>
            </div>
            @if((float) $sale->discount > 0)
                <div class="flex justify-between">
                    <span>Descuento</span>
                    <span>RD$ {{ number_format((float) $sale->discount, 2) }}</span>
                </div>
            @endif
            <div class="flex justify-between">
                <span>ITBIS</span>
                <span>RD$ {{ number_format((float) $sale->tax, 2) }}</span>
            </div>
            <div class="flex justify-between font-bold text-base pt-1 border-t border-dashed border-gray-300">
                <span>TOTAL</span>
                <span>RD$ {{ number_format((float) $sale->total, 2) }}</span>
            </div>

            @if($isCash)
                <div class="flex justify-between mt-2">
                    <span>Pagó con</span>
                    <span>RD$ {{ number_format((float) $tendered, 2) }}</span>
                </div>
                <div class="flex justify-between">
                    <span>Vuelto</span>
                    <span>RD$ {{ number_format((float) $change, 2) }}</span>
                </div>
            @endif

            <div class="flex justify-between mt-1">
                <span>Pago</span>
                <span>{{ $payLabel }}</span>
            </div>
        </div>

        <div class="text-center text-xs mt-4 text-gray-500">
            ¡Gracias por su compra!
        </div>
    </div>

    <div class="flex justify-center gap-3 mt-6 print:hidden">
        <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Resources\SaleResource::getUrl('index') }}">
            Volver
        </x-filament::button>
        <x-filament::button color="gray" tag="a" href="{{ route('print.ticket', $sale) }}" target="_blank">
            PDF 80mm
        </x-filament::button>
        <x-filament::button onclick="window.print()">
            Imprimir ticket
        </x-filament::button>
    </div>

    <style>
        @media print {
            .fi-topbar,
            .fi-sidebar,
            .fi-header,
            .print\:hidden {
                display: none !important;
            }
            body * { visibility: hidden; }
            #ticket, #ticket * { visibility: visible; }
            #ticket {
                position: absolute;
                left: 0;
                top: 0;
                width: 80mm;
                box-shadow: none !important;
            }
        }
    </style>
</x-filament-panels::page>
