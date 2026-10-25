<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <div class="mt-6 space-y-6">

        {{-- RESUMEN ITBIS --}}
        <x-filament::section>
            <x-slot name="heading">Resumen de ITBIS</x-slot>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-success-200 bg-success-50 p-5 dark:border-success-800 dark:bg-success-900/20">
                    <div class="text-sm text-gray-500 dark:text-gray-400">ITBIS Cobrado (Ventas)</div>
                    <div class="mt-1 text-2xl font-bold text-success-600 dark:text-success-400">
                        RD$ {{ number_format($this->itbisSummary['itbis_cobrado'], 2) }}
                    </div>
                    <div class="mt-1 text-xs text-gray-400">
                        Base: RD$ {{ number_format($this->itbisSummary['ventas_netas'], 2) }}
                    </div>
                </div>

                <div class="rounded-xl border border-danger-200 bg-danger-50 p-5 dark:border-danger-800 dark:bg-danger-900/20">
                    <div class="text-sm text-gray-500 dark:text-gray-400">ITBIS Pagado (Compras)</div>
                    <div class="mt-1 text-2xl font-bold text-danger-600 dark:text-danger-400">
                        RD$ {{ number_format($this->itbisSummary['itbis_pagado'], 2) }}
                    </div>
                    <div class="mt-1 text-xs text-gray-400">
                        Base: RD$ {{ number_format($this->itbisSummary['compras_netas'], 2) }}
                    </div>
                </div>

                <div @class([
                    'rounded-xl border p-5',
                    'border-info-200 bg-info-50 dark:border-info-800 dark:bg-info-900/20' => $this->itbisSummary['a_favor'],
                    'border-warning-200 bg-warning-50 dark:border-warning-800 dark:bg-warning-900/20' => ! $this->itbisSummary['a_favor'],
                ])>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $this->itbisSummary['a_favor'] ? 'ITBIS a Favor' : 'ITBIS a Pagar' }}
                    </div>
                    <div @class([
                        'mt-1 text-2xl font-bold',
                        'text-info-600 dark:text-info-400' => $this->itbisSummary['a_favor'],
                        'text-warning-600 dark:text-warning-400' => ! $this->itbisSummary['a_favor'],
                    ])>
                        RD$ {{ number_format(abs($this->itbisSummary['saldo']), 2) }}
                    </div>
                    <div class="mt-1 text-xs text-gray-400">
                        Cobrado − Pagado
                    </div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50">
                    <span class="text-gray-500">Ventas totales del período:</span>
                    <span class="font-semibold">RD$ {{ number_format($this->itbisSummary['ventas_totales'], 2) }}</span>
                </div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50">
                    <span class="text-gray-500">Compras recibidas del período:</span>
                    <span class="font-semibold">RD$ {{ number_format($this->itbisSummary['compras_totales'], 2) }}</span>
                </div>
            </div>
        </x-filament::section>

        {{-- DETALLE VENTAS --}}
        <x-filament::section>
            <x-slot name="heading">Detalle de Ventas por Día (con ITBIS)</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b dark:border-gray-700">
                            <th class="px-2 py-3 text-left">Fecha</th>
                            <th class="px-2 py-3 text-right">Cantidad</th>
                            <th class="px-2 py-3 text-right">Base Imponible</th>
                            <th class="px-2 py-3 text-right">ITBIS</th>
                            <th class="px-2 py-3 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->salesByDay as $day)
                            <tr class="border-b hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                                <td class="px-2 py-3 font-medium">
                                    {{ \Carbon\Carbon::parse($day['fecha'])->format('d/m/Y') }}
                                </td>
                                <td class="px-2 py-3 text-right">{{ $day['cantidad'] }}</td>
                                <td class="px-2 py-3 text-right">RD$ {{ number_format($day['base'], 2) }}</td>
                                <td class="px-2 py-3 text-right font-bold text-success-600 dark:text-success-400">
                                    RD$ {{ number_format($day['itbis'], 2) }}
                                </td>
                                <td class="px-2 py-3 text-right font-bold">
                                    RD$ {{ number_format($day['total'], 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-6 text-center text-gray-500">
                                    No hay ventas en este período
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if(count($this->salesByDay) > 0)
                        <tfoot>
                            <tr class="border-t-2 font-bold dark:border-gray-600">
                                <td class="px-2 py-3">TOTAL</td>
                                <td class="px-2 py-3 text-right">{{ collect($this->salesByDay)->sum('cantidad') }}</td>
                                <td class="px-2 py-3 text-right">RD$ {{ number_format($this->itbisSummary['ventas_netas'], 2) }}</td>
                                <td class="px-2 py-3 text-right text-success-600 dark:text-success-400">
                                    RD$ {{ number_format($this->itbisSummary['itbis_cobrado'], 2) }}
                                </td>
                                <td class="px-2 py-3 text-right">RD$ {{ number_format($this->itbisSummary['ventas_totales'], 2) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-filament::section>

        {{-- NOTA --}}
        <div class="rounded-xl bg-gray-50 p-4 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-400">
            <strong>Nota:</strong> Este reporte es orientativo según las ventas y compras registradas en el sistema.
            La declaración formal ante la DGII requiere Facturación Electrónica (e-CF) y los formularios oficiales.
        </div>

    </div>
</x-filament-panels::page>
