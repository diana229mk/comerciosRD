<x-filament-panels::page>
    <div class="mx-auto max-w-md">
        <div class="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="mb-2 text-xl font-bold text-gray-950 dark:text-white">
                Verificación en dos pasos
            </h2>
            <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                Introduce el código de 6 dígitos de tu app de autenticación
                (o un código de recuperación) para continuar al Panel Master.
            </p>

            <form wire:submit="verify">
                {{ $this->form }}

                <x-filament::button type="submit" class="mt-4 w-full">
                    Verificar y entrar
                </x-filament::button>
            </form>
        </div>
    </div>
</x-filament-panels::page>
