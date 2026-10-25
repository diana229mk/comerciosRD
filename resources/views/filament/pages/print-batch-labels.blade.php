<x-filament-panels::page>
    <form wire:submit.prevent="$refresh">{{ $this->form }}<div class="mt-2"><x-filament::button type="submit" color="gray">Actualizar</x-filament::button></div></form>

    @php $batches = $this->getBatches(); @endphp

    <div class="mt-4 overflow-x-auto rounded-xl bg-white dark:bg-gray-800 shadow">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-900"><tr class="text-left text-xs uppercase text-gray-500">
                <th class="px-3 py-2 w-8"></th>
                <th class="px-3 py-2">Producto</th>
                <th class="px-3 py-2">Lote</th>
                <th class="px-3 py-2">Vence</th>
                <th class="px-3 py-2 text-right">Cantidad</th>
                <th class="px-3 py-2 text-right">Precio</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($batches as $b)
                    <tr>
                        <td class="px-3 py-2"><input type="checkbox" wire:model.live="selected.{{ $b->id }}" /></td>
                        <td class="px-3 py-2">{{ $b->product?->name }}</td>
                        <td class="px-3 py-2">{{ $b->code }}</td>
                        <td class="px-3 py-2">{{ $b->expires_at?->format('d/m/Y') ?? '—' }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format((float) $b->quantity, 3) }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format((float) ($b->product?->price ?? 0), 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">Sin lotes.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
