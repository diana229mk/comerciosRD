<x-filament-panels::page>
    @php
        /** @var \App\Models\StockCount $count */
        $count = $this->getRecord();
        $items = $count->items()->with(['product', 'batch'])->get();
        $editable = $count->isEditable();
    @endphp

    <div class="fi-section grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Código</div>
            <div class="text-lg font-semibold">{{ $count->code }}</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Estado</div>
            <div class="text-lg font-semibold">{{ $count->statusLabel() }}</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Ítems</div>
            <div class="text-lg font-semibold">{{ $count->total_items }}</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="text-xs text-gray-500 uppercase">Impacto RD$</div>
            <div class="text-lg font-semibold">{{ number_format((float) $count->total_cost_impact, 2) }}</div>
        </div>
    </div>

    @if ($count->notes)
        <div class="mt-4 text-sm text-gray-700 dark:text-gray-300 italic">
            {{ $count->notes }}
        </div>
    @endif

    <div class="mt-6 overflow-x-auto rounded-xl bg-white dark:bg-gray-800 shadow">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-900">
                <tr class="text-left text-xs uppercase text-gray-500">
                    <th class="px-3 py-2">Producto</th>
                    <th class="px-3 py-2">Lote</th>
                    <th class="px-3 py-2">Vence</th>
                    <th class="px-3 py-2 text-right">Esperado</th>
                    <th class="px-3 py-2 text-right">Contado</th>
                    <th class="px-3 py-2 text-right">Diferencia</th>
                    <th class="px-3 py-2 text-right">Impacto RD$</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($items as $item)
                    @php
                        $counted = $this->counted[(string) $item->id] ?? null;
                        $diff = $counted === null || $counted === '' ? null : round((float) $counted - (float) $item->expected_quantity, 3);
                        $impact = $diff === null ? null : round($diff * (float) $item->unit_cost, 2);
                    @endphp
                    <tr>
                        <td class="px-3 py-2">{{ $item->product?->name ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $item->batch?->code ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $item->batch?->expires_at?->format('d/m/Y') ?? '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format((float) $item->expected_quantity, 3) }}</td>
                        <td class="px-3 py-2 text-right">
                            @if ($editable)
                                <input type="number" step="0.001" min="0"
                                    wire:model.defer="counted.{{ $item->id }}"
                                    class="w-24 text-right rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600" />
                            @else
                                {{ $item->counted_quantity !== null ? number_format((float) $item->counted_quantity, 3) : '—' }}
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right {{ $diff && $diff < 0 ? 'text-red-600' : ($diff && $diff > 0 ? 'text-green-600' : '') }}">
                            {{ $diff === null ? '—' : number_format($diff, 3) }}
                        </td>
                        <td class="px-3 py-2 text-right {{ $impact && $impact < 0 ? 'text-red-600' : '' }}">
                            {{ $impact === null ? '—' : number_format($impact, 2) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if (! $editable)
        <div class="mt-4 text-sm text-gray-500">
            Conteo cerrado el {{ $count->completed_at?->format('d/m/Y H:i') ?? $count->cancelled_at?->format('d/m/Y H:i') }}.
            Ítems con diferencia: <b>{{ $count->items_with_difference }}</b>.
        </div>
    @endif
</x-filament-panels::page>
