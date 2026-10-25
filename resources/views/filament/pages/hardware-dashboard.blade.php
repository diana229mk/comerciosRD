<x-filament-panels::page>
    @php $d = $this->getData(); @endphp

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="p-4 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800">
            <div class="text-xs uppercase text-green-700 dark:text-green-300">Ventas hoy</div>
            <div class="text-2xl font-bold">RD$ {{ number_format($d['salesToday'], 2) }}</div>
            <div class="text-xs text-green-600 dark:text-green-400">{{ $d['countToday'] }} transacciones</div>
        </div>
        <div class="p-4 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
            <div class="text-xs uppercase text-blue-700 dark:text-blue-300">Ventas del mes</div>
            <div class="text-2xl font-bold">RD$ {{ number_format($d['salesMonth'], 2) }}</div>
        </div>
        <div class="p-4 rounded-lg bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800">
            <div class="text-xs uppercase text-purple-700 dark:text-purple-300">Margen del mes</div>
            <div class="text-2xl font-bold">RD$ {{ number_format($d['margin'], 2) }}</div>
        </div>
        <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
            <div class="text-xs uppercase text-gray-700 dark:text-gray-300">Valor inventario</div>
            <div class="text-2xl font-bold">RD$ {{ number_format($d['invValue'], 2) }}</div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
        <a href="{{ url()->current().'/../alertas-inventario' }}" class="p-4 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 hover:shadow">
            <div class="text-xs uppercase text-amber-700 dark:text-amber-300">Stock bajo</div>
            <div class="text-2xl font-bold">{{ $d['low'] }}</div>
        </a>
        <a href="{{ url()->current().'/../alertas-inventario' }}" class="p-4 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 hover:shadow">
            <div class="text-xs uppercase text-blue-700 dark:text-blue-300">Sobre-inventario</div>
            <div class="text-2xl font-bold">{{ $d['over'] }}</div>
        </a>
        <a href="{{ url()->current().'/../alertas-inventario' }}" class="p-4 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 hover:shadow">
            <div class="text-xs uppercase text-red-700 dark:text-red-300">Dead stock (&gt;90d sin ventas)</div>
            <div class="text-2xl font-bold">{{ $d['dead'] }}</div>
        </a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
        <x-filament::section>
            <x-slot name="heading">Top productos del mes</x-slot>
            @if ($d['topProducts']->isEmpty())
                <p class="text-sm text-gray-500">Sin ventas todavía en el mes.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr><th class="text-left p-2">Producto</th><th class="text-right p-2">Uds</th><th class="text-right p-2">Total</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach ($d['topProducts'] as $p)
                            <tr>
                                <td class="p-2">{{ $p->product_name }}</td>
                                <td class="p-2 text-right">{{ number_format((float) $p->qty, 3) }}</td>
                                <td class="p-2 text-right">RD$ {{ number_format((float) $p->total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Cuentas por cobrar (aging)</x-slot>
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    <tr><td class="p-2">0-30 días</td><td class="p-2 text-right">RD$ {{ number_format($d['aging']['due_0_30'], 2) }}</td></tr>
                    <tr><td class="p-2">31-60 días</td><td class="p-2 text-right">RD$ {{ number_format($d['aging']['due_31_60'], 2) }}</td></tr>
                    <tr><td class="p-2">61-90 días</td><td class="p-2 text-right">RD$ {{ number_format($d['aging']['due_61_90'], 2) }}</td></tr>
                    <tr class="bg-red-50 dark:bg-red-900/10"><td class="p-2 font-semibold">+90 días</td><td class="p-2 text-right font-semibold">RD$ {{ number_format($d['aging']['due_90_plus'], 2) }}</td></tr>
                </tbody>
            </table>
        </x-filament::section>
    </div>
</x-filament-panels::page>
