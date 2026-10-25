<x-filament-panels::page>
    @php $orders = $this->readyOrders(); @endphp

    <div wire:poll.5s>
        <p class="text-sm text-gray-500 mb-4">
            Órdenes marcadas <strong>listas</strong> en cocina. Al entregar, salen de esta pantalla.
        </p>

        @if ($orders->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-12 text-center text-gray-500">
                Vacío de momento. Primero en <strong>Cocina</strong>:
                <br>Nuevas → <em>Marcar preparando</em> → <em>Marcar lista</em>.
                <br>Ahí es cuando el pedido pasa a esta pantalla.
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach ($orders as $order)
                    <article class="rounded-2xl border-2 border-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 p-5">
                        <div class="text-3xl font-black tracking-tight">{{ $order->order_number }}</div>
                        <div class="text-lg mt-1">{{ $order->customer_name ?: 'Cliente' }}</div>
                        <ul class="mt-3 text-base space-y-1">
                            @foreach ($order->items as $item)
                                <li>{{ number_format((float) $item->quantity, 0) }} × {{ $item->product_name }}</li>
                            @endforeach
                        </ul>
                        <button
                            type="button"
                            x-data
                            x-on:click.prevent="$wire.sendOut(@js((string) $order->id))"
                            class="mt-5 w-full rounded-xl bg-emerald-600 text-white text-lg font-bold py-3 hover:bg-emerald-700"
                        >
                            Entregar / salida
                        </button>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
