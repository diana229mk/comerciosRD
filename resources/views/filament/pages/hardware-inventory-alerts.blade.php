<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="p-4 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
            <div class="text-2xl font-bold text-amber-700 dark:text-amber-300">{{ count($low) }}</div>
            <div class="text-sm text-amber-900 dark:text-amber-200">Stock bajo</div>
        </div>
        <div class="p-4 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
            <div class="text-2xl font-bold text-blue-700 dark:text-blue-300">{{ count($over) }}</div>
            <div class="text-sm text-blue-900 dark:text-blue-200">Sobre-inventario</div>
        </div>
        <div class="p-4 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
            <div class="text-2xl font-bold text-red-700 dark:text-red-300">{{ count($dead) }}</div>
            <div class="text-sm text-red-900 dark:text-red-200">Dead stock (sin ventas 90 días)</div>
        </div>
    </div>

    @foreach ([['title' => 'Stock bajo', 'rows' => $low, 'field' => 'reorder_point'],
              ['title' => 'Sobre-inventario', 'rows' => $over, 'field' => 'max_stock'],
              ['title' => 'Dead stock (sin ventas en 90 días)', 'rows' => $dead, 'field' => null]] as $section)
        @if (! empty($section['rows']))
            <x-filament::section class="mt-4">
                <x-slot name="heading">{{ $section['title'] }}</x-slot>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="text-left p-2">Producto</th>
                                <th class="text-left p-2">SKU</th>
                                <th class="text-right p-2">Stock</th>
                                @if ($section['field'])
                                    <th class="text-right p-2">{{ $section['field'] === 'max_stock' ? 'Máx' : 'Punto reorden' }}</th>
                                @endif
                                <th class="text-right p-2">Costo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($section['rows'] as $p)
                                <tr>
                                    <td class="p-2 font-medium">{{ $p->name }}</td>
                                    <td class="p-2 font-mono text-xs">{{ $p->sku }}</td>
                                    <td class="p-2 text-right">{{ number_format((float) $p->stock, 3) }}</td>
                                    @if ($section['field'])
                                        <td class="p-2 text-right">{{ number_format((float) ($p->{$section['field']} ?? 0), 3) }}</td>
                                    @endif
                                    <td class="p-2 text-right">{{ number_format((float) $p->cost, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endforeach
</x-filament-panels::page>
