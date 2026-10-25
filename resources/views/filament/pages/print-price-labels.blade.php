<x-filament-panels::page>
    <form wire:submit.prevent="$refresh">{{ $this->form }}</form>

    @php
        $items = $this->getPrintableItems();
        $copies = max(1, (int) ($filters['copies'] ?? 1));
        $showPrice = (bool) ($filters['show_price'] ?? true);
        $showStock = (bool) ($filters['show_stock'] ?? true);
        $size = $filters['label_size'] ?? '40x25';
        [$w, $h] = array_map('intval', explode('x', $size));
    @endphp

    <div class="mt-4 overflow-x-auto rounded-xl bg-white dark:bg-gray-800 shadow">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-900">
                <tr class="text-left text-xs uppercase text-gray-500">
                    <th class="px-3 py-2 w-8"></th>
                    <th class="px-3 py-2">Producto</th>
                    <th class="px-3 py-2">Variante</th>
                    <th class="px-3 py-2">SKU</th>
                    <th class="px-3 py-2">Barcode</th>
                    <th class="px-3 py-2 text-right">Precio</th>
                    <th class="px-3 py-2 text-right">Stock</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($items as $item)
                    <tr>
                        <td class="px-3 py-2"><input type="checkbox" wire:model.live="selected.{{ $item['id'] }}"></td>
                        <td class="px-3 py-2">{{ $item['name'] }}</td>
                        <td class="px-3 py-2">{{ $item['variant_label'] ?? '—' }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $item['sku'] }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $item['barcode'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format((float) $item['price'], 2) }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format((float) $item['stock'], 3) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">Sin productos.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @php $selectedItems = $this->getSelectedPrintableItems(); @endphp
    @if (! empty($selectedItems))
        <div class="mt-3 flex justify-end">
            <x-filament::button tag="a" :href="$this->printUrl()" target="_blank" rel="noopener">
                Imprimir seleccionadas
            </x-filament::button>
        </div>

        <style>
            .crd-label-sheet { display: flex; flex-wrap: wrap; gap: 3mm; padding: 8px 0; }
            .crd-price-label {
                width: {{ $w }}mm; height: {{ $h }}mm;
                border: 1px solid #222; padding: 1.2mm 1.6mm;
                overflow: hidden; box-sizing: border-box;
                display: grid; grid-template-rows: auto minmax(0,1fr) auto; gap: 0.6mm;
                background: #fff; color: #111;
                font-family: Arial, Helvetica, sans-serif;
            }
            .crd-price-label .n {
                font-weight: 700; font-size: {{ $h >= 35 ? '9pt' : '7pt' }};
                line-height: 1.15; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            }
            .crd-price-label .b { min-height: 0; overflow: hidden; display: flex; align-items: center; }
            .crd-price-label .b svg { width: 100% !important; height: {{ $h >= 35 ? '11mm' : '7mm' }} !important; display: block; }
            .crd-price-label .r { display: flex; justify-content: space-between; align-items: flex-end; gap: 1mm; min-width: 0; }
            .crd-price-label .s { font-size: 6.5pt; font-family: ui-monospace, monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
            .crd-price-label .p { font-weight: 800; font-size: {{ $h >= 35 ? '12pt' : '9pt' }}; white-space: nowrap; }
        </style>

        <div class="mt-2 crd-label-sheet">
            @foreach ($selectedItems as $item)
                @for ($i = 0; $i < $copies; $i++)
                    <div class="crd-price-label">
                        <div class="n">{{ \Illuminate\Support\Str::limit($item['display_name'] ?? $item['name'], 32) }}</div>
                        <div class="b">{!! $this->renderBarcode((string) ($item['barcode'] ?: $item['sku'])) !!}</div>
                        <div class="r">
                            <span class="s">{{ $item['barcode'] ?: $item['sku'] }}</span>
                            @if ($showPrice)
                                <span class="p">RD$ {{ number_format((float) $item['price'], 2) }}</span>
                            @endif
                        </div>
                    </div>
                @endfor
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
