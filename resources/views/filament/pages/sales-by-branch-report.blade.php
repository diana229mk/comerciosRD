<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <div class="mt-6 space-y-6">

        {{-- RESUMEN CONSOLIDADO --}}
        <x-filament::section>
            <x-slot name="heading">
                Resumen del periodo
            </x-slot>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="p-4 bg-primary-50 dark:bg-primary-900/20 rounded-xl border border-primary-100 dark:border-primary-800">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Ventas Totales</div>
                    <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">
                        RD$ {{ number_format($this->totals['sales_total'], 2) }}
                    </div>
                </div>
                <div class="p-4 bg-success-50 dark:bg-success-900/20 rounded-xl border border-success-100 dark:border-success-800">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Cantidad de Ventas</div>
                    <div class="text-2xl font-bold text-success-600 dark:text-success-400">
                        {{ number_format($this->totals['sales_count']) }}
                    </div>
                </div>
                <div class="p-4 bg-warning-50 dark:bg-warning-900/20 rounded-xl border border-warning-100 dark:border-warning-800">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Gastos</div>
                    <div class="text-2xl font-bold text-warning-600 dark:text-warning-400">
                        RD$ {{ number_format($this->totals['expenses_total'], 2) }}
                    </div>
                </div>
                <div class="p-4 bg-info-50 dark:bg-info-900/20 rounded-xl border border-info-100 dark:border-info-800">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Neto</div>
                    <div class="text-2xl font-bold {{ $this->totals['net'] >= 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                        RD$ {{ number_format($this->totals['net'], 2) }}
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- DESGLOSE POR SUCURSAL --}}
        <x-filament::section>
            <x-slot name="heading">
                Desglose por sucursal
            </x-slot>

            @if (count($this->rows) === 0)
                <div class="p-6 text-center text-gray-500 dark:text-gray-400">
                    No hay operaciones registradas en el periodo seleccionado.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Sucursal</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Ventas</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Cant.</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Ticket prom.</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Gastos</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Neto</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($this->rows as $row)
                                <tr class="{{ $row['branch_id'] === null ? 'bg-gray-50 dark:bg-gray-900/40' : '' }}">
                                    <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">
                                        {{ $row['branch_name'] }}
                                    </td>
                                    <td class="px-4 py-2 text-sm text-right text-gray-900 dark:text-gray-100">
                                        RD$ {{ number_format($row['sales_total'], 2) }}
                                    </td>
                                    <td class="px-4 py-2 text-sm text-right text-gray-600 dark:text-gray-400">
                                        {{ number_format($row['sales_count']) }}
                                    </td>
                                    <td class="px-4 py-2 text-sm text-right text-gray-600 dark:text-gray-400">
                                        RD$ {{ number_format($row['avg_ticket'], 2) }}
                                    </td>
                                    <td class="px-4 py-2 text-sm text-right text-warning-600 dark:text-warning-400">
                                        RD$ {{ number_format($row['expenses_total'], 2) }}
                                    </td>
                                    <td class="px-4 py-2 text-sm text-right font-bold {{ $row['net'] >= 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                                        RD$ {{ number_format($row['net'], 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- MOVIMIENTOS DE INVENTARIO --}}
        <x-filament::section>
            <x-slot name="heading">
                Movimientos de inventario por sucursal
            </x-slot>

            @if (count($this->movements) === 0)
                <div class="p-6 text-center text-gray-500 dark:text-gray-400">
                    Sin movimientos registrados en el periodo.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Sucursal</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Compras</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Ventas</th>
                                <th class="px-4 py-2 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase">Ajustes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($this->movements as $row)
                                <tr>
                                    <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{{ $row['branch_name'] }}</td>
                                    <td class="px-4 py-2 text-sm text-right text-primary-600 dark:text-primary-400">{{ number_format($row['purchase']) }}</td>
                                    <td class="px-4 py-2 text-sm text-right text-success-600 dark:text-success-400">{{ number_format($row['sale']) }}</td>
                                    <td class="px-4 py-2 text-sm text-right text-warning-600 dark:text-warning-400">{{ number_format($row['adjustment']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

    </div>
</x-filament-panels::page>
