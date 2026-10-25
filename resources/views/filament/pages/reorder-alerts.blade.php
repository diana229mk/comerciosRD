<x-filament-panels::page>
    @php
        $suggestions = $this->getSuggestions();
        $total = $suggestions->count();
        $critical = $suggestions->where('urgency','critical')->count();
        $high = $suggestions->where('urgency','high')->count();
        $totalCost = $suggestions->sum(fn($s) => (float)($this->qty[(string)$s['product']->id] ?? $s['suggested_qty']) * (float)$s['product']->cost);
    @endphp

    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40 p-4 text-sm text-gray-700 dark:text-gray-200">
        <p class="font-semibold mb-1">El producto no se elimina cuando se acaba.</p>
        <p>
            El artículo de la lista sigue en el catálogo (no hay que crearlo otra vez).
            Cuando vuelvas a comprarlo, marca la fila, pon cuántas unidades llegaron
            y pulsa <strong>Ya lo compré — sumar al stock</strong>.
            Ahí sube el inventario y puedes venderlo otra vez.
        </p>
        <p class="mt-1 text-gray-500">
            Si todavía no te ha llegado, usa <strong>Generar orden de compra</strong> y, al recibir la caja, en Compras pulsa <em>Marcar como Recibida</em>.
        </p>
    </div>

    <form wire:submit.prevent="applyFilters">
        {{ $this->form }}
        <div class="mt-2">
            <x-filament::button type="submit" color="gray">Actualizar</x-filament::button>
        </div>
    </form>

    <div class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Productos a reponer</div>
            <div class="text-2xl font-semibold">{{ $total }}</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Críticos (sin stock)</div>
            <div class="text-2xl font-semibold text-red-600">{{ $critical }}</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Alta urgencia</div>
            <div class="text-2xl font-semibold text-orange-600">{{ $high }}</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Costo total estimado</div>
            <div class="text-2xl font-semibold">RD$ {{ number_format($totalCost, 2) }}</div>
        </div>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl bg-white dark:bg-gray-800 shadow">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-900">
                <tr class="text-left text-xs uppercase text-gray-500">
                    <th class="px-3 py-2 w-8"></th>
                    <th class="px-3 py-2">Producto</th>
                    <th class="px-3 py-2">Proveedor</th>
                    <th class="px-3 py-2 text-right">Stock</th>
                    <th class="px-3 py-2 text-right">Mín</th>
                    <th class="px-3 py-2 text-right">Consumo/día</th>
                    <th class="px-3 py-2 text-right">Cobertura</th>
                    <th class="px-3 py-2 text-right">Sugerido</th>
                    <th class="px-3 py-2 text-right">Costo RD$</th>
                    <th class="px-3 py-2">Urg.</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($suggestions as $s)
                    @php $id = (string) $s['product']->id; @endphp
                    <tr>
                        <td class="px-3 py-2">
                            <input type="checkbox" wire:model.live="selected.{{ $id }}" />
                        </td>
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ $s['product']->name }}</div>
                            <div class="text-xs text-gray-500">{{ $s['product']->sku }}</div>
                        </td>
                        <td class="px-3 py-2">{{ $s['supplier']?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($s['stock'], 3) }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($s['min_stock'], 3) }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($s['avg_daily'], 3) }}</td>
                        <td class="px-3 py-2 text-right">{{ $s['days_cover'] !== null ? $s['days_cover'].' d' : '—' }}</td>
                        <td class="px-3 py-2 text-right">
                            <input type="number" step="0.001" min="0"
                                wire:model.live.debounce.500ms="qty.{{ $id }}"
                                class="w-24 text-right rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600" />
                        </td>
                        <td class="px-3 py-2 text-right">
                            {{ number_format((float)($this->qty[$id] ?? $s['suggested_qty']) * (float) $s['product']->cost, 2) }}
                        </td>
                        <td class="px-3 py-2">
                            @php
                                $color = match ($s['urgency']) {
                                    'critical' => 'bg-red-100 text-red-800',
                                    'high' => 'bg-orange-100 text-orange-800',
                                    default => 'bg-yellow-100 text-yellow-800',
                                };
                            @endphp
                            <span class="px-2 py-0.5 text-xs rounded {{ $color }}">
                                {{ ['critical'=>'Crítica','high'=>'Alta','medium'=>'Media'][$s['urgency']] ?? $s['urgency'] }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-3 py-6 text-center text-gray-500">
                            No hay productos bajo el mínimo. 🎉
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
