<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @php
        $batches = $this->batches;
        $detail = $this->batchDetail;
        $selected = $detail['batch'];
    @endphp

    <div class="mt-6 space-y-6">

        {{-- KPIs rápidos --}}
        @php
            $totalBatches = count($batches);
            $totalRemaining = array_sum(array_column($batches, 'remaining'));
            $totalSold = array_sum(array_column($batches, 'sold'));
            $totalReturned = array_sum(array_column($batches, 'returned'));
        @endphp

        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs uppercase text-gray-500">Lotes</div>
                <div class="mt-1 text-2xl font-bold">{{ number_format($totalBatches) }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs uppercase text-gray-500">Restante en stock</div>
                <div class="mt-1 text-2xl font-bold text-info-600">{{ number_format($totalRemaining, 3) }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs uppercase text-gray-500">Vendido</div>
                <div class="mt-1 text-2xl font-bold text-success-600">{{ number_format($totalSold, 3) }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs uppercase text-gray-500">Devuelto</div>
                <div class="mt-1 text-2xl font-bold text-warning-600">{{ number_format($totalReturned, 3) }}</div>
            </div>
        </div>

        {{-- TABLA DE LOTES --}}
        <x-filament::section>
            <x-slot name="heading">
                Lotes filtrados
                <span class="text-xs font-normal text-gray-500">({{ count($batches) }} resultado{{ count($batches) === 1 ? '' : 's' }}, máx. 300)</span>
            </x-slot>

            <x-slot name="headerEnd">
                <x-filament::button
                    wire:click="exportCsv"
                    icon="heroicon-o-arrow-down-tray"
                    color="gray"
                    size="sm">
                    Exportar CSV
                </x-filament::button>
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr class="text-left text-xs uppercase text-gray-500">
                            <th class="px-3 py-2">Producto</th>
                            <th class="px-3 py-2">Lote</th>
                            <th class="px-3 py-2">Recibido</th>
                            <th class="px-3 py-2">Vence</th>
                            <th class="px-3 py-2 text-right">Inicial</th>
                            <th class="px-3 py-2 text-right">Restante</th>
                            <th class="px-3 py-2 text-right">Vendido</th>
                            <th class="px-3 py-2 text-right">Devuelto</th>
                            <th class="px-3 py-2">Estado</th>
                            <th class="px-3 py-2">Proveedor</th>
                            <th class="px-3 py-2 text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($batches as $b)
                            <tr class="{{ $selected && $selected->id === $b['id'] ? 'bg-primary-50 dark:bg-primary-900/20' : '' }}">
                                <td class="px-3 py-2">
                                    <div class="font-medium">{{ $b['product'] }}</div>
                                    @if ($b['sku'])
                                        <div class="text-xs text-gray-500">SKU: {{ $b['sku'] }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $b['code'] ?: '—' }}</td>
                                <td class="px-3 py-2">{{ $b['received_at'] ?: '—' }}</td>
                                <td class="px-3 py-2">{{ $b['expires_at'] ?: '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format($b['initial'], 3) }}</td>
                                <td class="px-3 py-2 text-right font-medium">{{ number_format($b['remaining'], 3) }}</td>
                                <td class="px-3 py-2 text-right text-success-600">{{ number_format($b['sold'], 3) }}</td>
                                <td class="px-3 py-2 text-right text-warning-600">{{ number_format($b['returned'], 3) }}</td>
                                <td class="px-3 py-2">
                                    @php
                                        $color = match ($b['status']) {
                                            'vencido' => 'danger',
                                            'próximo' => 'warning',
                                            'agotado' => 'gray',
                                            default => 'success',
                                        };
                                    @endphp
                                    <x-filament::badge :color="$color">{{ ucfirst($b['status']) }}</x-filament::badge>
                                </td>
                                <td class="px-3 py-2 text-xs">{{ $b['supplier'] ?: '—' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <x-filament::button
                                        size="xs"
                                        color="primary"
                                        wire:click="selectBatch('{{ $b['id'] }}')">
                                        Ver ciclo
                                    </x-filament::button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-3 py-10 text-center text-gray-500">
                                    No hay lotes con los filtros actuales.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- DETALLE DEL LOTE SELECCIONADO --}}
        @if ($selected)
            <x-filament::section>
                <x-slot name="heading">
                    Ciclo del lote <span class="font-mono">{{ $selected->code ?: $selected->id }}</span>
                    · {{ $selected->product?->name }}
                </x-slot>

                <x-slot name="headerEnd">
                    <x-filament::button
                        wire:click="clearSelection"
                        color="gray"
                        size="sm"
                        icon="heroicon-o-x-mark">
                        Cerrar
                    </x-filament::button>
                </x-slot>

                {{-- Totales --}}
                <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div class="rounded-lg bg-info-50 p-3 dark:bg-info-900/20">
                        <div class="text-xs text-gray-500">Recibido</div>
                        <div class="text-lg font-bold">{{ number_format($detail['totals']['received'], 3) }}</div>
                    </div>
                    <div class="rounded-lg bg-success-50 p-3 dark:bg-success-900/20">
                        <div class="text-xs text-gray-500">Vendido</div>
                        <div class="text-lg font-bold">{{ number_format($detail['totals']['sold'], 3) }}</div>
                    </div>
                    <div class="rounded-lg bg-warning-50 p-3 dark:bg-warning-900/20">
                        <div class="text-xs text-gray-500">Devuelto al lote</div>
                        <div class="text-lg font-bold">{{ number_format($detail['totals']['returned'], 3) }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                        <div class="text-xs text-gray-500">Restante</div>
                        <div class="text-lg font-bold">{{ number_format($detail['totals']['remaining'], 3) }}</div>
                    </div>
                </div>

                {{-- Origen --}}
                <div class="mb-4 rounded-lg border border-gray-200 p-4 dark:border-gray-800">
                    <div class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                        🏭 Origen (compra que creó el lote)
                    </div>
                    @if ($detail['origin'])
                        <div class="grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                            <div><span class="text-gray-500">Compra:</span> <b>{{ $detail['origin']['purchase_number'] ?: '—' }}</b></div>
                            <div><span class="text-gray-500">Proveedor:</span> {{ $detail['origin']['supplier'] ?: '—' }}</div>
                            <div><span class="text-gray-500">Recibido:</span> {{ $detail['origin']['received_at'] }}</div>
                            <div><span class="text-gray-500">Cantidad:</span> {{ number_format($detail['origin']['quantity'], 3) }}</div>
                            <div><span class="text-gray-500">Costo unitario:</span> RD$ {{ number_format($detail['origin']['cost'], 2) }}</div>
                            <div><span class="text-gray-500">Vence:</span> {{ $selected->expires_at?->format('Y-m-d') ?: '—' }}</div>
                        </div>
                    @else
                        <div class="text-sm text-gray-500">Sin movimiento de compra asociado (lote creado manualmente).</div>
                    @endif
                </div>

                {{-- Ventas --}}
                <div class="mb-4">
                    <div class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                        🛒 Ventas del lote ({{ count($detail['sales']) }})
                    </div>
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr class="text-left text-xs uppercase text-gray-500">
                                    <th class="px-3 py-2">Fecha</th>
                                    <th class="px-3 py-2">Venta</th>
                                    <th class="px-3 py-2">Cliente</th>
                                    <th class="px-3 py-2 text-right">Cantidad</th>
                                    <th class="px-3 py-2 text-right">Costo unit.</th>
                                    <th class="px-3 py-2 text-right">Ya devuelto</th>
                                    <th class="px-3 py-2 text-right">Retornable</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse ($detail['sales'] as $s)
                                    <tr>
                                        <td class="px-3 py-2">{{ $s['sold_at'] }}</td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ $s['sale_number'] }}</td>
                                        <td class="px-3 py-2">{{ $s['customer'] ?: '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format($s['quantity'], 3) }}</td>
                                        <td class="px-3 py-2 text-right">RD$ {{ number_format($s['unit_cost'], 2) }}</td>
                                        <td class="px-3 py-2 text-right text-warning-600">{{ number_format($s['returned'], 3) }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format($s['remaining'], 3) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">Sin ventas de este lote.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Devoluciones --}}
                <div>
                    <div class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                        ↩️ Devoluciones al lote ({{ count($detail['returns']) }})
                    </div>
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr class="text-left text-xs uppercase text-gray-500">
                                    <th class="px-3 py-2">Fecha</th>
                                    <th class="px-3 py-2">Venta origen</th>
                                    <th class="px-3 py-2 text-right">Cantidad</th>
                                    <th class="px-3 py-2">Nota</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse ($detail['returns'] as $r)
                                    <tr>
                                        <td class="px-3 py-2">{{ $r['date'] }}</td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ $r['sale_number'] ?: '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format($r['quantity'], 3) }}</td>
                                        <td class="px-3 py-2 text-xs text-gray-600">{{ $r['note'] ?: '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">Sin devoluciones registradas.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
