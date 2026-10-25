<x-filament-panels::page>
    @if ($result)
        <div class="p-3 mb-4 rounded-lg bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-200 text-sm">
            {{ $result }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <x-filament::section>
            <x-slot name="heading">Importar productos</x-slot>
            <x-slot name="description">
                Columnas soportadas: <code>nombre</code>, <code>sku</code>, <code>codigo_de_barras</code>,
                <code>categoria</code>, <code>precio</code>, <code>costo</code>, <code>stock</code>,
                <code>stock_minimo</code>, <code>stock_maximo</code>, <code>unidad</code>,
                <code>marca</code>, <code>codigo_proveedor</code>, <code>sinonimos</code> (separados por |),
                <code>es_kit</code>, <code>rastrea_serie</code>, <code>activo</code>, <code>descripcion</code>.
            </x-slot>
            <form wire:submit="importProducts">
                {{ $this->productsForm }}
                <div class="mt-4">
                    <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray">
                        Importar productos
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Importar lista de precios de proveedor</x-slot>
            <x-slot name="description">
                Columnas soportadas: <code>sku</code> o <code>codigo_de_barras</code>,
                <code>codigo_proveedor</code>, <code>costo</code>,
                <code>lead_time</code> (días), <code>preferido</code> (sí/no).
                Solo actualiza productos existentes.
            </x-slot>
            <form wire:submit="importPrices">
                {{ $this->pricesForm }}
                <div class="mt-4">
                    <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray">
                        Importar precios
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
