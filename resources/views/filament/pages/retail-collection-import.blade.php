<x-filament-panels::page>
    @if ($result)
        <div class="mb-4 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-200">
            {{ $result }}
        </div>
    @endif

    <x-filament::section>
        <x-slot name="heading">Importar colección boutique</x-slot>
        <x-slot name="description">
            Columnas sugeridas: <code>nombre</code>, <code>marca</code>, <code>temporada</code>, <code>coleccion</code>,
            <code>estilo</code>, <code>talla</code>, <code>color</code>, <code>sku</code>, <code>codigo_de_barras</code>,
            <code>precio</code>, <code>costo</code>, <code>stock</code>, <code>activo</code>.
        </x-slot>

        <form wire:submit="importCollection">
            {{ $this->form }}
            <div class="mt-4">
                <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray">
                    Importar colección
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
