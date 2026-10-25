<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit" icon="heroicon-o-check">
                Guardar configuración
            </x-filament::button>

            <x-filament::button
                tag="a"
                color="gray"
                icon="heroicon-o-building-storefront"
                :href="\App\Filament\Pages\BusinessProfile::getUrl()"
            >
                Ir a Datos del Negocio (RNC)
            </x-filament::button>

            <x-filament::button
                tag="a"
                color="gray"
                icon="heroicon-o-hashtag"
                :href="\App\Filament\Resources\EcfSequenceResource::getUrl('index')"
            >
                Secuencias Fiscales
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
