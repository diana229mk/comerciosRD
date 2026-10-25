<x-filament-panels::page>
    @php $d = $this->getData(); @endphp

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <a href="{{ \App\Filament\Pages\ReorderAlerts::getUrl() }}" class="rounded-xl bg-white dark:bg-gray-800 shadow p-4 hover:shadow-lg">
            <div class="text-xs text-gray-500 uppercase">Stock bajo</div>
            <div class="text-3xl font-bold text-orange-600">{{ $d['lowStock'] ?? 0 }}</div>
            <div class="text-xs text-gray-500">Agotados: {{ $d['outOfStock'] ?? 0 }}</div>
        </a>
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Vencidos con stock</div>
            <div class="text-3xl font-bold text-red-600">{{ $d['expired'] ?? 0 }}</div>
            <div class="text-xs text-gray-500">Próximos a vencer: {{ $d['nearExpiry'] ?? 0 }}</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Venta hoy</div>
            <div class="text-3xl font-bold">RD$ {{ number_format((float) ($d['today'] ?? 0), 2) }}</div>
            <div class="text-xs text-gray-500">{{ $d['countToday'] ?? 0 }} ventas</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Venta del mes</div>
            <div class="text-3xl font-bold">RD$ {{ number_format((float) ($d['month'] ?? 0), 2) }}</div>
            <div class="text-xs text-gray-500">Controlados 30d: {{ $d['controlled'] ?? 0 }}</div>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Ingresos (30 días)</div>
            <div class="text-2xl font-bold">RD$ {{ number_format((float) ($d['revenue'] ?? 0), 2) }}</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Costo (30 días)</div>
            <div class="text-2xl font-bold">RD$ {{ number_format((float) ($d['cost'] ?? 0), 2) }}</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Margen bruto</div>
            <div class="text-2xl font-bold text-green-600">RD$ {{ number_format((float) ($d['margin'] ?? 0), 2) }}</div>
            <div class="text-xs text-gray-500">{{ $d['marginPct'] ?? 0 }}%</div>
        </div>
    </div>

    <div class="mt-6 rounded-xl bg-white dark:bg-gray-800 shadow">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold">Top 10 productos vendidos (30 días)</h3>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-900"><tr class="text-left text-xs uppercase text-gray-500">
                <th class="px-3 py-2">#</th>
                <th class="px-3 py-2">Producto</th>
                <th class="px-3 py-2 text-right">Unidades</th>
                <th class="px-3 py-2 text-right">Ingreso RD$</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($d['top'] ?? [] as $i => $t)
                    <tr>
                        <td class="px-3 py-2">{{ $i + 1 }}</td>
                        <td class="px-3 py-2">{{ $t->product_name }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format((float) $t->qty, 3) }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format((float) $t->total, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">Sin ventas en el rango.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
