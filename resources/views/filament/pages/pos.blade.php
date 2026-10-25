<x-filament-panels::page>
    <style>
        .fi-page:has(#pos-root) .fi-page-content,
        .fi-page:has(#pos-root) .fi-main,
        .fi-page:has(#pos-root) .fi-main-ctn {
            max-width: 100%;
            overflow-x: clip;
        }
    </style>
    @if (! empty($posConfig))
        @include('pos._app', ['posConfig' => $posConfig, 'embedded' => true])
    @else
        <p class="text-sm text-gray-500">Sin negocio activo.</p>
    @endif
</x-filament-panels::page>
