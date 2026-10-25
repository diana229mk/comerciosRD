<x-filament-panels::page>
    @php $s = $this->getSummary(); $rows = $this->getRows(); @endphp

    <div class="flex flex-wrap gap-4 items-end mb-4">
        <div>
            <label class="text-xs font-medium">Período (días)</label>
            <select wire:model.live="days" class="rounded border-gray-300 dark:bg-gray-800">
                <option value="30">30 días</option>
                <option value="60">60 días</option>
                <option value="90">90 días</option>
                <option value="180">180 días</option>
                <option value="365">1 año</option>
            </select>
        </div>
        <div>
            <label class="text-xs font-medium">Filtrar por clase</label>
            <select wire:model.live="filterClass" class="rounded border-gray-300 dark:bg-gray-800">
                <option value="all">Todas</option>
                <option value="rapida">Rotación rápida (&lt;30d)</option>
                <option value="media">Rotación media (30-90d)</option>
                <option value="lenta">Rotación lenta (&gt;90d)</option>
                <option value="sin-movimiento">Sin movimiento</option>
            </select>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
        <div class="p-3 rounded-lg bg-green-50 dark:bg-green-900/20">
            <div class="text-xs uppercase text-green-700 dark:text-green-300">Rotación rápida</div>
            <div class="text-2xl font-bold">{{ $s['rapida'] ?? 0 }}</div>
        </div>
        <div class="p-3 rounded-lg bg-yellow-50 dark:bg-yellow-900/20">
            <div class="text-xs uppercase text-yellow-700 dark:text-yellow-300">Rotación media</div>
            <div class="text-2xl font-bold">{{ $s['media'] ?? 0 }}</div>
        </div>
        <div class="p-3 rounded-lg bg-orange-50 dark:bg-orange-900/20">
            <div class="text-xs uppercase text-orange-700 dark:text-orange-300">Rotación lenta</div>
            <div class="text-2xl font-bold">{{ $s['lenta'] ?? 0 }}</div>
        </div>
        <div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20">
            <div class="text-xs uppercase text-red-700 dark:text-red-300">Sin movimiento</div>
            <div class="text-2xl font-bold">{{ $s['sin_movimiento'] ?? 0 }}</div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4 text-sm">
        <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
            <div class="text-xs uppercase">Valor inventario</div>
            <div class="text-lg font-semibold">RD$ {{ number_format($s['total_inv_value'] ?? 0, 2) }}</div>
        </div>
        <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
            <div class="text-xs uppercase">Ingresos del período</div>
            <div class="text-lg font-semibold">RD$ {{ number_format($s['total_revenue'] ?? 0, 2) }}</div>
        </div>
        <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
            <div class="text-xs uppercase">COGS del período</div>
            <div class="text-lg font-semibold">RD$ {{ number_format($s['total_cogs'] ?? 0, 2) }}</div>
        </div>
    </div>

    <x-filament::section>
        <x-slot name="heading">Detalle por producto ({{ $rows->count() }})</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="text-left p-2">Producto</th>
                        <th class="text-left p-2">SKU</th>
                        <th class="text-right p-2">Stock</th>
                        <th class="text-right p-2">Uds vendidas</th>
                        <th class="text-right p-2">Prom/día</th>
                        <th class="text-right p-2">Días stock</th>
                        <th class="text-right p-2">Rotación</th>
                        <th class="text-right p-2">Valor inv.</th>
                        <th class="text-center p-2">Clase</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @foreach ($rows as $r)
                        <tr>
                            <td class="p-2">{{ $r->name }}</td>
                            <td class="p-2 text-gray-500">{{ $r->sku }}</td>
                            <td class="p-2 text-right">{{ number_format($r->stock, 3) }}</td>
                            <td class="p-2 text-right">{{ number_format($r->qty_sold, 3) }}</td>
                            <td class="p-2 text-right">{{ number_format($r->avg_daily, 3) }}</td>
                            <td class="p-2 text-right">{{ $r->days_of_stock !== null ? number_format($r->days_of_stock, 1) : '—' }}</td>
                            <td class="p-2 text-right">{{ number_format($r->turnover, 2) }}</td>
                            <td class="p-2 text-right">RD$ {{ number_format($r->inv_value, 2) }}</td>
                            <td class="p-2 text-center">
                                @if ($r->class === 'rapida')
                                    <span class="px-2 py-1 rounded bg-green-100 text-green-800 text-xs">Rápida</span>
                                @elseif ($r->class === 'media')
                                    <span class="px-2 py-1 rounded bg-yellow-100 text-yellow-800 text-xs">Media</span>
                                @elseif ($r->class === 'lenta')
                                    <span class="px-2 py-1 rounded bg-orange-100 text-orange-800 text-xs">Lenta</span>
                                @else
                                    <span class="px-2 py-1 rounded bg-red-100 text-red-800 text-xs">Sin mov.</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @if ($rows->isEmpty())
                        <tr><td colspan="9" class="p-4 text-center text-gray-500">Sin datos para el período seleccionado.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
