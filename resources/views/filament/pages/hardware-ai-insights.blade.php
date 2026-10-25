<x-filament-panels::page>
    @php
        $forecast = $this->getForecast();
        $cross = $this->getCrossSell();
        $liquid = $this->getLiquidation();
        $totalPurchase = $forecast->sum('suggested_cost');
    @endphp

    <div class="p-4 mb-4 rounded-lg bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 text-sm">
        <strong>💡 IA local:</strong> los siguientes insights se generan con análisis estadístico sobre tus ventas
        históricas — no requieren APIs externas ni suben datos a la nube.
    </div>

    {{-- ============ PREDICCIÓN DE DEMANDA ============ --}}
    <x-filament::section>
        <x-slot name="heading">🔮 Predicción de demanda y orden sugerida</x-slot>
        <x-slot name="description">
            Media móvil ponderada (30/60/90 días) + ajuste por tendencia. Ordenado por urgencia de reorden.
        </x-slot>

        <div class="flex gap-4 items-end mb-3">
            <div>
                <label class="text-xs font-medium">Horizonte (días)</label>
                <select wire:model.live="forecastDays" class="rounded border-gray-300 dark:bg-gray-800">
                    <option value="15">15 días</option>
                    <option value="30">30 días</option>
                    <option value="60">60 días</option>
                    <option value="90">90 días</option>
                </select>
            </div>
            <div class="ml-auto text-right">
                <div class="text-xs uppercase text-gray-500">Inversión total sugerida</div>
                <div class="text-2xl font-bold text-indigo-700 dark:text-indigo-300">
                    RD$ {{ number_format($totalPurchase, 2) }}
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="text-left p-2">Producto</th>
                        <th class="text-right p-2">Stock</th>
                        <th class="text-right p-2">Prom/día (30d)</th>
                        <th class="text-right p-2">Tendencia</th>
                        <th class="text-right p-2">Días stock</th>
                        <th class="text-right p-2">Proyección {{ $forecastDays }}d</th>
                        <th class="text-right p-2">Sugerido</th>
                        <th class="text-right p-2">Costo</th>
                        <th class="text-center p-2">Urgencia</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($forecast as $r)
                        <tr>
                            <td class="p-2">
                                <div class="font-medium">{{ $r->name }}</div>
                                <div class="text-xs text-gray-500">{{ $r->sku }}</div>
                            </td>
                            <td class="p-2 text-right">{{ number_format($r->stock, 2) }}</td>
                            <td class="p-2 text-right">{{ number_format($r->avg_30, 3) }}</td>
                            <td class="p-2 text-right {{ $r->trend > 10 ? 'text-green-600' : ($r->trend < -10 ? 'text-red-600' : '') }}">
                                {{ $r->trend > 0 ? '+' : '' }}{{ $r->trend }}%
                            </td>
                            <td class="p-2 text-right">{{ $r->days_of_stock !== null ? number_format($r->days_of_stock, 1) : '—' }}</td>
                            <td class="p-2 text-right">{{ number_format($r->forecast, 2) }}</td>
                            <td class="p-2 text-right font-semibold">{{ $r->suggested_qty }}</td>
                            <td class="p-2 text-right">RD$ {{ number_format($r->suggested_cost, 2) }}</td>
                            <td class="p-2 text-center">
                                @if ($r->urgency === 2)
                                    <span class="px-2 py-1 rounded bg-red-100 text-red-800 text-xs font-semibold">🔴 Crítico</span>
                                @elseif ($r->urgency === 1)
                                    <span class="px-2 py-1 rounded bg-yellow-100 text-yellow-800 text-xs">🟡 Alerta</span>
                                @else
                                    <span class="px-2 py-1 rounded bg-gray-100 text-gray-600 text-xs">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="p-4 text-center text-gray-500">No hay productos que requieran reorden en este horizonte.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- ============ CROSS-SELL ============ --}}
    <x-filament::section class="mt-4">
        <x-slot name="heading">🛒 Cross-sell (productos que se llevan juntos)</x-slot>
        <x-slot name="description">
            Análisis de tickets: pares de productos que aparecen en la misma venta.
            Úsalo para colocación en tienda, combos y sugerencias en POS.
        </x-slot>

        <div class="flex gap-4 items-end mb-3">
            <div>
                <label class="text-xs font-medium">Ventana</label>
                <select wire:model.live="crossDays" class="rounded border-gray-300 dark:bg-gray-800">
                    <option value="30">30 días</option>
                    <option value="60">60 días</option>
                    <option value="90">90 días</option>
                    <option value="180">180 días</option>
                </select>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="text-left p-2">Producto A</th>
                        <th class="text-left p-2">Producto B</th>
                        <th class="text-right p-2">Veces juntos</th>
                        <th class="text-right p-2">A → B</th>
                        <th class="text-right p-2">B → A</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($cross as $c)
                        <tr>
                            <td class="p-2">{{ $c->name_a }}</td>
                            <td class="p-2">{{ $c->name_b }}</td>
                            <td class="p-2 text-right font-semibold">{{ $c->count }}</td>
                            <td class="p-2 text-right">{{ $c->conf_a_to_b }}%</td>
                            <td class="p-2 text-right">{{ $c->conf_b_to_a }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-4 text-center text-gray-500">Aún no hay suficientes ventas para detectar patrones (necesitas ≥2 ventas con los mismos pares).</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- ============ LIQUIDACIÓN ============ --}}
    <x-filament::section class="mt-4">
        <x-slot name="heading">💸 Recomendaciones de liquidación</x-slot>
        <x-slot name="description">
            Productos sin ventas prolongadas con stock significativo. Descuento sugerido según antigüedad.
        </x-slot>

        <div class="flex gap-4 items-end mb-3">
            <div>
                <label class="text-xs font-medium">Días sin venta</label>
                <select wire:model.live="deadDays" class="rounded border-gray-300 dark:bg-gray-800">
                    <option value="60">60 días</option>
                    <option value="90">90 días</option>
                    <option value="120">120 días</option>
                    <option value="180">180 días</option>
                </select>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="text-left p-2">Producto</th>
                        <th class="text-right p-2">Stock</th>
                        <th class="text-right p-2">Valor inv.</th>
                        <th class="text-right p-2">Días sin venta</th>
                        <th class="text-right p-2">Precio actual</th>
                        <th class="text-right p-2">Descuento</th>
                        <th class="text-right p-2">Precio sugerido</th>
                        <th class="text-left p-2">Acción</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($liquid as $r)
                        <tr>
                            <td class="p-2">
                                <div class="font-medium">{{ $r->name }}</div>
                                <div class="text-xs text-gray-500">{{ $r->sku }}</div>
                            </td>
                            <td class="p-2 text-right">{{ number_format($r->stock, 2) }}</td>
                            <td class="p-2 text-right">RD$ {{ number_format($r->inv_value, 2) }}</td>
                            <td class="p-2 text-right">{{ $r->days_since_last_sale }}</td>
                            <td class="p-2 text-right">RD$ {{ number_format($r->price, 2) }}</td>
                            <td class="p-2 text-right font-semibold text-red-600">-{{ $r->suggested_discount }}%</td>
                            <td class="p-2 text-right">RD$ {{ number_format($r->suggested_price, 2) }}</td>
                            <td class="p-2 text-xs {{ $r->margin_at_discount < 0 ? 'text-red-600' : '' }}">{{ $r->action }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-4 text-center text-gray-500">No hay candidatos a liquidación en este período.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
