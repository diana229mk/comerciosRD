<x-filament-panels::page>
    <div class="space-y-4">
        <x-filament::section>
            <x-slot name="heading">Productos por debajo del punto de reorden</x-slot>
            <x-slot name="description">
                Se listan los productos cuyo stock actual está en o debajo de su punto de reorden.
                La cantidad sugerida usa <em>reorder_quantity</em>, o <em>max_stock − stock</em> si no está definido.
            </x-slot>

            @if (empty($suggestions))
                <p class="text-sm text-gray-500">No hay productos que necesiten reposición ahora mismo.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="text-left p-2">Producto</th>
                                <th class="text-left p-2">SKU</th>
                                <th class="text-right p-2">Stock</th>
                                <th class="text-right p-2">Reorden</th>
                                <th class="text-right p-2">Sugerido</th>
                                <th class="text-left p-2">Proveedor</th>
                                <th class="text-right p-2">Último costo</th>
                                <th class="text-right p-2">Estimado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($suggestions as $s)
                                @php
                                    $qty = (float) $s['suggested_qty'];
                                    $cost = (float) ($s['last_cost'] ?? 0);
                                @endphp
                                <tr>
                                    <td class="p-2 font-medium">{{ $s['product']->name }}</td>
                                    <td class="p-2 font-mono text-xs">{{ $s['product']->sku }}</td>
                                    <td class="p-2 text-right">{{ number_format((float) $s['product']->stock, 3) }}</td>
                                    <td class="p-2 text-right">{{ number_format((float) ($s['product']->reorder_point ?? $s['product']->min_stock ?? 0), 3) }}</td>
                                    <td class="p-2 text-right font-semibold">{{ number_format($qty, 3) }}</td>
                                    <td class="p-2">{{ $s['supplier']?->name ?? '—' }}</td>
                                    <td class="p-2 text-right">{{ number_format($cost, 2) }}</td>
                                    <td class="p-2 text-right">{{ number_format($qty * $cost, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
