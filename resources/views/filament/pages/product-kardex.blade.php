<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
        El kardex es la <strong>historia de un solo producto</strong>: cuánto había, qué entró, qué salió y cuánto quedó.
        Elige producto y fechas, pulsa <strong>Consultar</strong>.
        <span class="text-gray-500">Saldo inicial + entradas − salidas = saldo. El último saldo debe parecerse al stock actual.</span>
    </p>

    <form wire:submit.prevent="$refresh">
        {{ $this->form }}
        <div class="mt-2">
            <x-filament::button type="submit" color="primary">Consultar</x-filament::button>
        </div>
    </form>

    @php $data = $this->getMovements(); @endphp

    @if ($data['product'])
        <div class="mt-4 rounded-xl bg-white dark:bg-gray-800 shadow p-4">
            <div class="flex flex-wrap justify-between gap-4 items-center">
                <div>
                    <div class="text-lg font-semibold">{{ $data['product']->name }}</div>
                    <div class="text-xs text-gray-500">SKU: {{ $data['product']->sku ?: '—' }}</div>
                </div>
                <div class="flex gap-6 text-right">
                    <div>
                        <div class="text-xs text-gray-500">Había al inicio del rango</div>
                        <div class="text-lg font-semibold">{{ number_format((float) $data['opening'], 3) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Quedó al final del rango</div>
                        <div class="text-lg font-semibold">{{ number_format((float) $data['closing'], 3) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Stock en el sistema ahora</div>
                        <div class="text-lg font-semibold">{{ number_format((float) $data['product']->stock, 3) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 overflow-x-auto rounded-xl bg-white dark:bg-gray-800 shadow">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr class="text-left text-xs uppercase text-gray-500">
                        <th class="px-3 py-2">Cuándo</th>
                        <th class="px-3 py-2">Qué pasó</th>
                        <th class="px-3 py-2">Lote</th>
                        <th class="px-3 py-2 text-right">Entró</th>
                        <th class="px-3 py-2 text-right">Salió</th>
                        <th class="px-3 py-2 text-right">Quedó</th>
                        <th class="px-3 py-2 text-right">Costo unit.</th>
                        <th class="px-3 py-2">Nota</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($data['rows'] as $r)
                        <tr>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $r['date']->format('d/m/Y H:i') }}</td>
                            <td class="px-3 py-2">{{ $r['type'] }}</td>
                            <td class="px-3 py-2">{{ $r['batch'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-right text-green-700">{{ $r['in'] > 0 ? number_format($r['in'], 3) : '' }}</td>
                            <td class="px-3 py-2 text-right text-red-700">{{ $r['out'] > 0 ? number_format($r['out'], 3) : '' }}</td>
                            <td class="px-3 py-2 text-right font-medium">{{ number_format($r['balance'], 3) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($r['cost'], 2) }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r['reason'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center text-gray-500">Sin movimientos en esas fechas. Amplía el rango o vende/compra ese producto.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <p class="mt-6 text-sm text-gray-500">Elige un producto y pulsa Consultar.</p>
    @endif
</x-filament-panels::page>
