<x-filament-panels::page>
    @php $links = $this->links(); @endphp

    <p class="text-sm text-gray-600 dark:text-gray-300 mb-6">
        Armas el pedido, confirmas y emites el <strong>ticket</strong>.
        El cobro lo hace el cajero en el POS.
    </p>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        @if ($links['quotes'])
            <a href="{{ $links['quotes'] }}" class="rounded-xl border border-amber-200 dark:border-amber-800 bg-white dark:bg-gray-900 p-5 hover:border-amber-400">
                <div class="text-lg font-semibold">Cotizaciones</div>
                <p class="text-sm text-gray-500 mt-1">Nueva preventa → Confirmar y emitir ticket.</p>
            </a>
        @endif

        @if ($links['orders'])
            <a href="{{ $links['orders'] }}" class="rounded-xl border border-amber-200 dark:border-amber-800 bg-white dark:bg-gray-900 p-5 hover:border-amber-400">
                <div class="text-lg font-semibold">Órdenes</div>
                <p class="text-sm text-gray-500 mt-1">Pedidos del local y ticket al confirmar.</p>
            </a>
        @endif

        @if ($links['customers'])
            <a href="{{ $links['customers'] }}" class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-5 hover:border-amber-400">
                <div class="text-lg font-semibold">Clientes / talleres</div>
                <p class="text-sm text-gray-500 mt-1">Dar de alta a quién le cotizas.</p>
            </a>
        @endif
    </div>

    @if (! $links['quotes'] && ! $links['orders'] && ! $links['customers'])
        <p class="text-sm text-gray-500">No hay módulos de preventa en este negocio. Pide al dueño o a ComerciosRD que activen Órdenes o Cotizaciones.</p>
    @endif
</x-filament-panels::page>
