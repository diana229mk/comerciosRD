<x-filament-panels::page>
    @if (empty($menuUrl))
        <p class="text-sm text-gray-500">Sin negocio activo.</p>
    @else
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-filament::section>
                <x-slot name="heading">Código para la mesa</x-slot>
                <div class="flex flex-col items-center gap-4">
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        {!! $qrSvg !!}
                    </div>
                    <p class="text-center text-sm text-gray-500">
                        {{ $itemCount }} platos en {{ $categoryCount }} {{ $categoryCount === 1 ? 'categoría' : 'categorías' }}
                    </p>
                    <div class="flex flex-wrap justify-center gap-2">
                        <x-filament::button tag="a" href="{{ $menuUrl }}" target="_blank" color="gray">
                            Ver menú
                        </x-filament::button>
                        <x-filament::button tag="a" href="{{ $posterUrl }}" target="_blank" color="primary">
                            Imprimir cartel
                        </x-filament::button>
                        <x-filament::button tag="a" href="{{ $downloadUrl }}" color="success">
                            Descargar QR
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Enlace</x-slot>
                <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">
                    El cliente escanea el QR y abre esta dirección. No necesita login.
                    Solo ve nombre, descripción y precio (no costo ni stock).
                </p>
                <label class="mb-1 block text-xs font-medium text-gray-500">URL pública</label>
                <input
                    id="menu-qr-url"
                    type="text"
                    readonly
                    value="{{ $menuUrl }}"
                    class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800"
                    onclick="this.select()"
                >
                <p class="mt-4 text-xs text-gray-500">
                    Los platos salen de <strong>Menú / Productos</strong> activos con precio.
                    Los ingredientes de receta no aparecen en la carta.
                </p>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
