<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <div class="mt-6 space-y-6">

        {{-- RESUMEN DE VENTAS --}}
        <x-filament::section>
            <x-slot name="heading">
                Resumen de Ventas
            </x-slot>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="p-4 bg-primary-50 dark:bg-primary-900/20 rounded-xl border border-primary-100 dark:border-primary-800">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Total Ventas</div>
                    <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">
                        RD$ {{ number_format($this->salesSummary['total'], 2) }}
                    </div>
                </div>
                <div class="p-4 bg-success-50 dark:bg-success-900/20 rounded-xl border border-success-100 dark:border-success-800">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Cantidad de Ventas</div>
                    <div class="text-2xl font-bold text-success-600 dark:text-success-400">
                        {{ $this->salesSummary['count'] }}
                    </div>
                </div>
                <div class="p-4 bg-warning-50 dark:bg-warning-900/20 rounded-xl border border-warning-100 dark:border-warning-800">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Ticket Promedio</div>
                    <div class="text-2xl font-bold text-warning-600 dark:text-warning-400">
                        RD$ {{ number_format($this->salesSummary['average'], 2) }}
                    </div>
                </div>
                <div class="p-4 bg-info-50 dark:bg-info-900/20 rounded-xl border border-info-100 dark:border-info-800">
                    <div class="text-sm text-gray-500 dark:text-gray-400">ITBIS Cobrado</div>
                    <div class="text-2xl font-bold text-info-600 dark:text-info-400">
                        RD$ {{ number_format($this->salesSummary['tax'], 2) }}
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                <div class="p-4 border rounded-xl dark:border-gray-700">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Efectivo</div>
                    <div class="text-lg font-bold text-success-600 dark:text-success-400">RD$ {{ number_format($this->salesSummary['cash'], 2) }}</div>
                </div>
                <div class="p-4 border rounded-xl dark:border-gray-700">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Tarjeta</div>
                    <div class="text-lg font-bold text-primary-600 dark:text-primary-400">RD$ {{ number_format($this->salesSummary['card'], 2) }}</div>
                </div>
                <div class="p-4 border rounded-xl dark:border-gray-700">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Descuentos dados</div>
                    <div class="text-lg font-bold text-danger-600 dark:text-danger-400">RD$ {{ number_format($this->salesSummary['discount'], 2) }}</div>
                </div>
            </div>
        </x-filament::section>

        {{-- GANANCIAS --}}
        <x-filament::section>
            <x-slot name="heading">Ganancias y Márgenes</x-slot>

            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-xl">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Ingresos</div>
                    <div class="text-xl font-bold">RD$ {{ number_format($this->profitSummary['revenue'], 2) }}</div>
                </div>
                <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-xl">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Costo de Productos</div>
                    <div class="text-xl font-bold text-danger-600 dark:text-danger-400">RD$ {{ number_format($this->profitSummary['cost'], 2) }}</div>
                </div>
                <div class="p-4 bg-success-50 dark:bg-success-900/20 rounded-xl">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Ganancia Bruta</div>
                    <div class="text-xl font-bold text-success-600 dark:text-success-400">RD$ {{ number_format($this->profitSummary['gross_profit'], 2) }}</div>
                </div>
                <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-xl">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Gastos Operativos</div>
                    <div class="text-xl font-bold text-danger-600 dark:text-danger-400">RD$ {{ number_format($this->profitSummary['expenses'], 2) }}</div>
                </div>
                <div class="p-4 bg-primary-50 dark:bg-primary-900/20 rounded-xl">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Ganancia Neta</div>
                    <div class="text-xl font-bold {{ $this->profitSummary['net_profit'] >= 0 ? 'text-primary-600 dark:text-primary-400' : 'text-danger-600 dark:text-danger-400' }}">
                        RD$ {{ number_format($this->profitSummary['net_profit'], 2) }}
                    </div>
                </div>
                <div class="p-4 bg-warning-50 dark:bg-warning-900/20 rounded-xl">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Margen Bruto</div>
                    <div class="text-xl font-bold text-warning-600 dark:text-warning-400">{{ number_format($this->profitSummary['margin'], 1) }}%</div>
                </div>
            </div>
        </x-filament::section>

        {{-- VENTAS POR DÍA --}}
        <x-filament::section>
            <x-slot name="heading">Ventas por Día</x-slot>
            <x-slot name="description">Detalle día a día del período seleccionado</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b dark:border-gray-700">
                            <th class="text-left py-3 px-2">Fecha</th>
                            <th class="text-right py-3 px-2">Cantidad</th>
                            <th class="text-right py-3 px-2">Total</th>
                            <th class="text-right py-3 px-2">ITBIS</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->salesByDay as $day)
                            <tr class="border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">
                                <td class="py-3 px-2 font-medium">
                                    {{ \Illuminate\Support\Carbon::parse($day['date'])->format('d/m/Y') }}
                                </td>
                                <td class="py-3 px-2 text-right tabular-nums">{{ $day['total_sales'] }}</td>
                                <td class="py-3 px-2 text-right font-bold tabular-nums">RD$ {{ number_format((float) $day['total_amount'], 2) }}</td>
                                <td class="py-3 px-2 text-right text-gray-500 tabular-nums">RD$ {{ number_format((float) $day['total_tax'], 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center text-gray-500">No hay ventas en este período</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- PRODUCTOS MÁS VENDIDOS --}}
        <x-filament::section>
            <x-slot name="heading">Top 10 Productos Más Vendidos</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b dark:border-gray-700">
                            <th class="text-left py-3 px-2">#</th>
                            <th class="text-left py-3 px-2">Producto</th>
                            <th class="text-right py-3 px-2">Cantidad</th>
                            <th class="text-right py-3 px-2">Ingresos</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->topProducts as $index => $product)
                            <tr class="border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">
                                <td class="py-3 px-2 text-gray-500">{{ $index + 1 }}</td>
                                <td class="py-3 px-2 font-medium">{{ $product['product_name'] }}</td>
                                <td class="py-3 px-2 text-right">
                                    <span class="px-2 py-1 bg-success-100 text-success-700 dark:bg-success-900 dark:text-success-300 rounded-full text-xs font-bold">
                                        {{ $product['total_quantity'] }}
                                    </span>
                                </td>
                                <td class="py-3 px-2 text-right font-bold tabular-nums">RD$ {{ number_format((float) $product['total_revenue'], 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center text-gray-500">No hay ventas en este período</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @if (filament()->getTenant()?->isRetailStore())
            @php $retailSummary = $this->retailVariantSummary; @endphp
            <x-filament::section>
                <x-slot name="heading">Ventas por Talla / Color / Estilo</x-slot>
                <x-slot name="description">Matriz boutique para decidir reabastecimiento y liquidación.</x-slot>

                <div class="grid grid-cols-1 xl:grid-cols-4 gap-4">
                    <div>
                        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Top variantes</h3>
                        <div class="space-y-2">
                            @forelse ($retailSummary['variants'] ?? [] as $row)
                                <div class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                                    <span class="font-medium">{{ $row['label'] }}</span>
                                    <span class="text-sm text-gray-500">{{ number_format((float) $row['quantity'], 2) }} uds</span>
                                </div>
                            @empty
                                <div class="text-sm text-gray-500">Sin variantes vendidas.</div>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Tallas</h3>
                        <div class="space-y-2">
                            @forelse ($retailSummary['sizes'] ?? [] as $row)
                                <div class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                                    <span class="font-medium">{{ $row['label'] }}</span>
                                    <span class="text-sm text-gray-500">{{ number_format((float) $row['quantity'], 2) }} uds</span>
                                </div>
                            @empty
                                <div class="text-sm text-gray-500">Sin tallas.</div>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Colores</h3>
                        <div class="space-y-2">
                            @forelse ($retailSummary['colors'] ?? [] as $row)
                                <div class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                                    <span class="font-medium">{{ $row['label'] }}</span>
                                    <span class="text-sm text-gray-500">{{ number_format((float) $row['quantity'], 2) }} uds</span>
                                </div>
                            @empty
                                <div class="text-sm text-gray-500">Sin colores.</div>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Estilos</h3>
                        <div class="space-y-2">
                            @forelse ($retailSummary['styles'] ?? [] as $row)
                                <div class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                                    <span class="font-medium">{{ $row['label'] }}</span>
                                    <span class="text-sm text-gray-500">{{ number_format((float) $row['quantity'], 2) }} uds</span>
                                </div>
                            @empty
                                <div class="text-sm text-gray-500">Sin estilos.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </x-filament::section>

            @php $retailCollectionSummary = $this->retailCollectionSeasonSummary; @endphp
            <x-filament::section>
                <x-slot name="heading">Detalle por Colección y Temporada</x-slot>
                <x-slot name="description">Análisis para compras, markdown y liquidación por ciclo.</x-slot>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                    <div>
                        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Temporadas</h3>
                        <div class="space-y-2">
                            @forelse ($retailCollectionSummary['seasons'] ?? [] as $row)
                                <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="font-medium">{{ $row['label'] }}</span>
                                        <span class="text-sm text-gray-500">{{ number_format((float) $row['quantity'], 2) }} uds</span>
                                    </div>
                                    <div class="text-xs text-gray-500">RD$ {{ number_format((float) $row['revenue'], 2) }}</div>
                                </div>
                            @empty
                                <div class="text-sm text-gray-500">Sin temporadas vendidas.</div>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Colecciones</h3>
                        <div class="space-y-2">
                            @forelse ($retailCollectionSummary['collections'] ?? [] as $row)
                                <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                                    <div class="flex items-center justify-between">
                                        <span class="font-medium">{{ $row['label'] }}</span>
                                        <span class="text-sm text-gray-500">{{ number_format((float) $row['quantity'], 2) }} uds</span>
                                    </div>
                                    <div class="text-xs text-gray-500">RD$ {{ number_format((float) $row['revenue'], 2) }}</div>
                                </div>
                            @empty
                                <div class="text-sm text-gray-500">Sin colecciones vendidas.</div>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">Cruce temporada / colección</h3>
                        <div class="space-y-2">
                            @forelse ($retailCollectionSummary['matrix'] ?? [] as $row)
                                <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                                    <div class="font-medium">{{ $row['season'] }} · {{ $row['collection'] }}</div>
                                    <div class="flex items-center justify-between text-xs text-gray-500">
                                        <span>{{ number_format((float) $row['quantity'], 2) }} uds</span>
                                        <span>RD$ {{ number_format((float) $row['revenue'], 2) }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="text-sm text-gray-500">Sin cruces en el período.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </x-filament::section>
        @endif

        {{-- CUENTAS POR COBRAR Y PAGAR --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <x-filament::section>
                <x-slot name="heading">Cuentas por Cobrar</x-slot>
                <div class="space-y-4">
                    <div class="flex justify-between items-center p-3 bg-warning-50 dark:bg-warning-900/20 rounded-lg">
                        <span>Total Pendiente</span>
                        <span class="font-bold text-warning-600 dark:text-warning-400 text-lg tabular-nums">RD$ {{ number_format($this->receivablesSummary['pending'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-danger-50 dark:bg-danger-900/20 rounded-lg">
                        <span>Vencidas</span>
                        <span class="font-bold text-danger-600 dark:text-danger-400 text-lg tabular-nums">RD$ {{ number_format($this->receivablesSummary['overdue'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center p-3 border rounded-lg dark:border-gray-700">
                        <span>Cantidad de cuentas</span>
                        <span class="font-bold">{{ $this->receivablesSummary['count'] }}</span>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Cuentas por Pagar</x-slot>
                <div class="space-y-4">
                    <div class="flex justify-between items-center p-3 bg-warning-50 dark:bg-warning-900/20 rounded-lg">
                        <span>Total Pendiente</span>
                        <span class="font-bold text-warning-600 dark:text-warning-400 text-lg tabular-nums">RD$ {{ number_format($this->payablesSummary['pending'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-danger-50 dark:bg-danger-900/20 rounded-lg">
                        <span>Vencidas</span>
                        <span class="font-bold text-danger-600 dark:text-danger-400 text-lg tabular-nums">RD$ {{ number_format($this->payablesSummary['overdue'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center p-3 border rounded-lg dark:border-gray-700">
                        <span>Cantidad de cuentas</span>
                        <span class="font-bold">{{ $this->payablesSummary['count'] }}</span>
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- INVENTARIO --}}
        <x-filament::section>
            <x-slot name="heading">Valor del Inventario</x-slot>

            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-xl">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Valor al Costo</div>
                    <div class="text-xl font-bold">RD$ {{ number_format($this->inventoryValue['total_cost'], 2) }}</div>
                </div>
                <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-xl">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Valor a Precio de Venta</div>
                    <div class="text-xl font-bold">RD$ {{ number_format($this->inventoryValue['total_price'], 2) }}</div>
                </div>
                <div class="p-4 bg-success-50 dark:bg-success-900/20 rounded-xl">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Ganancia Potencial</div>
                    <div class="text-xl font-bold text-success-600 dark:text-success-400">RD$ {{ number_format($this->inventoryValue['potential_profit'], 2) }}</div>
                </div>
                <div class="p-4 bg-warning-50 dark:bg-warning-900/20 rounded-xl">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Stock Bajo</div>
                    <div class="text-xl font-bold text-warning-600 dark:text-warning-400">{{ $this->inventoryValue['low_stock'] }}</div>
                </div>
                <div class="p-4 bg-danger-50 dark:bg-danger-900/20 rounded-xl">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Sin Stock</div>
                    <div class="text-xl font-bold text-danger-600 dark:text-danger-400">{{ $this->inventoryValue['out_of_stock'] }}</div>
                </div>
                <div class="p-4 bg-primary-50 dark:bg-primary-900/20 rounded-xl">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Total Productos</div>
                    <div class="text-xl font-bold text-primary-600 dark:text-primary-400">{{ $this->inventoryValue['total_products'] }}</div>
                </div>
            </div>
        </x-filament::section>

        {{-- RESUMEN DE CAJA --}}
        <x-filament::section>
            <x-slot name="heading">Resumen de Caja</x-slot>
            <x-slot name="description">Sesiones cerradas en el período</x-slot>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div class="p-4 border rounded-xl dark:border-gray-700">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Sesiones cerradas</div>
                    <div class="text-xl font-bold">{{ $this->cashSessionsSummary['count'] }}</div>
                </div>
                <div class="p-4 border rounded-xl dark:border-gray-700">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Ventas en efectivo (cajas)</div>
                    <div class="text-xl font-bold text-success-600 dark:text-success-400">
                        RD$ {{ number_format($this->cashSessionsSummary['total_sales_cash'], 2) }}
                    </div>
                </div>
                <div class="p-4 border rounded-xl dark:border-gray-700">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Diferencia acumulada</div>
                    @php $diff = (float) $this->cashSessionsSummary['total_difference']; @endphp
                    <div class="text-xl font-bold {{ $diff > 0 ? 'text-success-600' : ($diff < 0 ? 'text-danger-600' : '') }}">
                        RD$ {{ number_format($diff, 2) }}
                        <span class="text-xs font-normal text-gray-400">
                            {{ $diff > 0 ? '(sobrante)' : ($diff < 0 ? '(faltante)' : '(cuadre)') }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b dark:border-gray-700">
                            <th class="text-left py-3 px-2">Apertura</th>
                            <th class="text-left py-3 px-2">Usuario</th>
                            <th class="text-right py-3 px-2">Inicial</th>
                            <th class="text-right py-3 px-2">Ventas ef.</th>
                            <th class="text-right py-3 px-2">Contado</th>
                            <th class="text-right py-3 px-2">Diferencia</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->cashSessionsSummary['sessions'] as $session)
                            <tr class="border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800">
                                <td class="py-3 px-2 whitespace-nowrap">{{ $session['opened_at'] }}</td>
                                <td class="py-3 px-2">{{ $session['user'] }}</td>
                                <td class="py-3 px-2 text-right tabular-nums">RD$ {{ number_format($session['opening_amount'], 2) }}</td>
                                <td class="py-3 px-2 text-right tabular-nums">RD$ {{ number_format($session['total_sales_cash'], 2) }}</td>
                                <td class="py-3 px-2 text-right tabular-nums">
                                    {{ $session['closing_amount'] === null ? '—' : 'RD$ '.number_format($session['closing_amount'], 2) }}
                                </td>
                                <td class="py-3 px-2 text-right tabular-nums font-semibold
                                    {{ ($session['difference'] ?? 0) > 0 ? 'text-success-600' : (($session['difference'] ?? 0) < 0 ? 'text-danger-600' : '') }}">
                                    {{ $session['difference'] === null ? '—' : 'RD$ '.number_format($session['difference'], 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-6 text-center text-gray-500">
                                    No hay sesiones de caja cerradas en este período
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

    </div>
</x-filament-panels::page>
