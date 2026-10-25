<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Generador de matriz talla × color</x-slot>
        <x-slot name="description">
            Crea las variantes del producto (ej. S/M/L × Negro/Blanco/Azul) y genera SKU/barcode por combinación.
        </x-slot>

        <form wire:submit="generateMatrix">
            {{ $this->form }}

            <div class="mt-4 flex justify-end">
                <x-filament::button type="submit" icon="heroicon-o-swatch">
                    Generar variantes
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
