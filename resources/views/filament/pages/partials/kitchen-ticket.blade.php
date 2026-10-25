@php
    /** @var \App\Models\Order $order */
    $colors = [
        'startPreparing' => 'background:#d97706;color:#fff',
        'markReady' => 'background:#059669;color:#fff',
        'sendOut' => 'background:#047857;color:#fff',
    ];
    $btn = $colors[$action] ?? 'background:#111;color:#fff';
@endphp
<article
    wire:key="kitchen-{{ $order->id }}"
    class="mb-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3 shadow-sm"
>
    <div class="flex justify-between gap-2 items-start">
        <div>
            <div class="font-bold text-lg leading-tight">{{ $order->order_number }}</div>
            <div class="text-sm text-gray-600 dark:text-gray-300">{{ $order->customer_name ?: 'Sin nombre' }}</div>
            <div class="text-xs text-gray-400">{{ $order->created_at?->diffForHumans() }}</div>
        </div>
        <div class="text-sm font-semibold">RD$ {{ number_format((float) $order->total, 2) }}</div>
    </div>
    <ul class="mt-2 text-sm space-y-0.5">
        @foreach ($order->items as $item)
            <li>
                <span class="font-medium">{{ number_format((float) $item->quantity, 0) }}×</span>
                {{ $item->product_name }}
            </li>
        @endforeach
    </ul>
    @if ($order->notes)
        <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">{{ $order->notes }}</p>
    @endif
    <button
        type="button"
        x-data
        x-on:click.prevent="$wire.{{ $action }}(@js((string) $order->id))"
        wire:loading.attr="disabled"
        wire:target="{{ $action }}"
        class="mt-3 w-full rounded-md text-sm font-semibold py-2.5"
        style="{{ $btn }}"
    >
        {{ $actionLabel }}
    </button>
</article>
