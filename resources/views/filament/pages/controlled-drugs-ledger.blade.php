<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @php
        $entries = $this->entries;
        $summary = $this->summary;
        $totalEntries = 0.0;
        $totalExits = 0.0;
        foreach ($entries as $e) {
            $e['is_entry'] ? $totalEntries += $e['quantity'] : $totalExits += $e['quantity'];
        }
    @endphp

    <div class="mt-6 space-y-6">

        {{-- KPIs --}}
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div class="text-xs uppercase text-gray-500">Asientos</div>
                <div class="mt-1 text-2xl font-bold">{{ number_format(count($entries)) }}</div>
            </div>
            <div class="rounded-xl border border-success-200 bg-success-50 p-4 dark:border-success-800 dark:bg-success-900/20">
                <div class="text-xs uppercase text-gray-500">Entradas</div>
                <div class="mt-1 text-2xl font-bold text-success-700">{{ number_format($totalEntries, 3) }}</div>
            </div>
            <div class="rounded-xl border border-danger-200 bg-danger-50 p-4 dark:border-danger-800 dark:bg-danger-900/20">
                <div class="text-xs uppercase text-gray-500">Salidas</div>
                <div class="mt-1 text-2xl font-bold text-danger-700">{{ number_format($totalExits, 3) }}</div>
            </div>
            <div class="rounded-xl border border-info-200 bg-info-50 p-4 dark:border-info-800 dark:bg-info-900/20">
                <div class="text-xs uppercase text-gray-500">Productos controlados</div>
                <div class="mt-1 text-2xl font-bold text-info-700">{{ count($summary) }}</div>
            </div>
        </div>

        {{-- Resumen por producto --}}
        <x-filament::section>
            <x-slot name="heading">Saldo por producto</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr class="text-left text-xs uppercase text-gray-500">
                            <th class="px-3 py-2">Producto</th>
                            <th class="px-3 py-2">Clasificación</th>
                            <th class="px-3 py-2 text-right">Entradas</th>
                            <th class="px-3 py-2 text-right">Salidas</th>
                            <th class="px-3 py-2 text-right">Saldo actual</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($summary as $s)
                            <tr>
                                <td class="px-3 py-2 font-medium">{{ $s['product'] }}</td>
                                <td class="px-3 py-2">
                                    <x-filament::badge color="warning">{{ str_replace('_', ' ', ucfirst($s['controlled_type'])) }}</x-filament::badge>
                                </td>
                                <td class="px-3 py-2 text-right text-success-600">+{{ number_format($s['entries'], 3) }}</td>
                                <td class="px-3 py-2 text-right text-danger-600">-{{ number_format($s['exits'], 3) }}</td>
                                <td class="px-3 py-2 text-right font-bold">{{ number_format($s['balance'], 3) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">Sin asientos en el período.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- Libro detallado --}}
        <x-filament::section>
            <x-slot name="heading">
                Libro detallado
                <span class="text-xs font-normal text-gray-500">({{ count($entries) }} asiento{{ count($entries) === 1 ? '' : 's' }}, máx. 1000)</span>
            </x-slot>

            <x-slot name="headerEnd">
                <x-filament::button
                    wire:click="exportCsv"
                    icon="heroicon-o-arrow-down-tray"
                    color="gray"
                    size="sm">
                    Exportar CSV
                </x-filament::button>
                <x-filament::button
                    tag="a"
                    href="{{ route('print.digemaps-ledger', ['from' => $this->date_from, 'to' => $this->date_to, 'anexo' => $this->anexo ?? null, 'product_id' => $this->product_id ?? null]) }}"
                    target="_blank"
                    icon="heroicon-o-document-arrow-down"
                    color="primary"
                    size="sm">
                    PDF DIGEMAPS
                </x-filament::button>
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr class="text-left uppercase text-gray-500">
                            <th class="px-2 py-2">Fecha</th>
                            <th class="px-2 py-2">Producto</th>
                            <th class="px-2 py-2">Anexo</th>
                            <th class="px-2 py-2">Movimiento</th>
                            <th class="px-2 py-2 text-right">Cantidad</th>
                            <th class="px-2 py-2 text-right">Saldo</th>
                            <th class="px-2 py-2">Lote</th>
                            <th class="px-2 py-2">Documento</th>
                            <th class="px-2 py-2">Receta</th>
                            <th class="px-2 py-2">Paciente</th>
                            <th class="px-2 py-2">Médico</th>
                            <th class="px-2 py-2">Exequátur</th>
                            <th class="px-2 py-2">Usuario</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($entries as $e)
                            <tr>
                                <td class="px-2 py-2 whitespace-nowrap">{{ $e['date'] }}</td>
                                <td class="px-2 py-2">
                                    <div class="font-medium">{{ $e['product'] }}</div>
                                    @if ($e['sku']) <div class="text-gray-500">{{ $e['sku'] }}</div> @endif
                                </td>
                                <td class="px-2 py-2">
                                    <x-filament::badge color="warning">{{ str_replace('_',' ', $e['controlled_type']) }}</x-filament::badge>
                                </td>
                                <td class="px-2 py-2">
                                    <x-filament::badge :color="$e['is_entry'] ? 'success' : 'danger'">
                                        {{ $e['movement_label'] }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-2 py-2 text-right font-mono {{ $e['is_entry'] ? 'text-success-600' : 'text-danger-600' }}">
                                    {{ $e['is_entry'] ? '+' : '-' }}{{ number_format($e['quantity'], 3) }}
                                </td>
                                <td class="px-2 py-2 text-right font-mono font-bold">{{ number_format($e['balance_after'], 3) }}</td>
                                <td class="px-2 py-2 font-mono">{{ $e['batch_code'] ?: '—' }}</td>
                                <td class="px-2 py-2 font-mono">{{ $e['document_number'] ?: '—' }}</td>
                                <td class="px-2 py-2 font-mono">{{ $e['prescription_code'] ?: '—' }}</td>
                                <td class="px-2 py-2">
                                    @if ($e['patient_name'])
                                        <div>{{ $e['patient_name'] }}</div>
                                        <div class="text-gray-500">{{ $e['patient_document'] }}</div>
                                    @else — @endif
                                </td>
                                <td class="px-2 py-2">{{ $e['doctor_name'] ?: '—' }}</td>
                                <td class="px-2 py-2 font-mono">{{ $e['doctor_exequatur'] ?: '—' }}</td>
                                <td class="px-2 py-2">{{ $e['user'] ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="13" class="px-3 py-8 text-center text-gray-500">Sin asientos en el período.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
