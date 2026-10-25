<x-filament-panels::page>
    @php
        $d = $this->getData();
        $pendingOrders = $d['board']->get('pendiente', collect());
        $prepOrders = $d['board']->get('en_preparacion', collect());
        $readyOrders = $d['board']->get('lista', collect());
    @endphp

    <div wire:poll.8s class="space-y-4">
        <p class="text-sm text-gray-500">
            Tablero de cocina. Lo que se cobra en caja o se toma en Órdenes aparece aquí.
            No es el dashboard de ventas del negocio.
        </p>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-950/20 p-3 min-h-[24rem]">
                <h3 class="font-semibold text-amber-800 dark:text-amber-200 mb-1">1. Nuevas</h3>
                <p class="text-xs text-amber-700/80 dark:text-amber-300/80 mb-3">{{ $d['pending'] }} en cola</p>
                @forelse ($pendingOrders as $order)
                    @include('filament.pages.partials.kitchen-ticket', ['order' => $order, 'action' => 'startPreparing', 'actionLabel' => 'Marcar preparando'])
                @empty
                    <p class="text-sm text-gray-500">Nada nuevo. Cobra en caja o toma una orden.</p>
                @endforelse
            </div>

            <div class="rounded-xl border border-orange-200 dark:border-orange-800 bg-orange-50/50 dark:bg-orange-950/20 p-3 min-h-[24rem]">
                <h3 class="font-semibold text-orange-800 dark:text-orange-200 mb-1">2. Preparando</h3>
                <p class="text-xs text-orange-700/80 dark:text-orange-300/80 mb-3">{{ $d['kitchen'] }} en cocina</p>
                @forelse ($prepOrders as $order)
                    @include('filament.pages.partials.kitchen-ticket', ['order' => $order, 'action' => 'markReady', 'actionLabel' => 'Marcar lista'])
                @empty
                    <p class="text-sm text-gray-500">Cocina libre.</p>
                @endforelse
            </div>

            <div class="rounded-xl border border-emerald-200 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-950/20 p-3 min-h-[24rem]">
                <h3 class="font-semibold text-emerald-800 dark:text-emerald-200 mb-1">3. Listo → salida</h3>
                <p class="text-xs text-emerald-700/80 dark:text-emerald-300/80 mb-3">{{ $d['ready'] }} en ventana</p>
                @forelse ($readyOrders as $order)
                    @include('filament.pages.partials.kitchen-ticket', ['order' => $order, 'action' => 'sendOut', 'actionLabel' => 'Entregar / salida'])
                @empty
                    <p class="text-sm text-gray-500">Nada en ventana. Abre <strong>Cocina → Salida</strong> en pantalla grande.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-filament-panels::page>
