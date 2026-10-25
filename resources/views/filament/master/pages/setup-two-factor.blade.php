<x-filament-panels::page>
    <div class="mx-auto max-w-xl">
        <div class="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            @if ($enabled && count($recoveryCodes))
                <h2 class="mb-2 text-xl font-bold text-gray-950 dark:text-white">
                    ¡2FA activado!
                </h2>
                <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                    Guarda estos códigos de recuperación en un lugar seguro. Cada uno solo se puede usar una vez.
                </p>

                <ul class="mb-6 grid grid-cols-2 gap-2 rounded-xl bg-gray-50 p-4 font-mono text-sm dark:bg-gray-800">
                    @foreach ($recoveryCodes as $recoveryCode)
                        <li class="select-all">{{ $recoveryCode }}</li>
                    @endforeach
                </ul>

                <x-filament::button wire:click="continueToDashboard" class="w-full">
                    Continuar al Panel Master
                </x-filament::button>
            @else
                <h2 class="mb-2 text-xl font-bold text-gray-950 dark:text-white">
                    Configura tu autenticación de dos factores
                </h2>
                <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                    Escanea el código QR con Google Authenticator, Authy o Microsoft Authenticator
                    e ingresa el código de 6 dígitos para activar el 2FA. Es obligatorio para el Panel Master.
                </p>

                @if ($qrSvg)
                    <div class="mb-4 flex justify-center rounded-xl bg-white p-4 dark:bg-gray-800">
                        {!! $qrSvg !!}
                    </div>
                @endif

                @if ($secret)
                    <p class="mb-6 text-center text-xs text-gray-500 dark:text-gray-400">
                        Si no puedes escanear, ingresa esta clave manualmente:
                        <span class="mt-1 block select-all font-mono text-sm font-semibold text-gray-800 dark:text-gray-200">
                            {{ $secret }}
                        </span>
                    </p>
                @endif

                <form wire:submit="enableTwoFactor">
                    {{ $this->form }}

                    <x-filament::button type="submit" class="mt-4 w-full">
                        Activar 2FA
                    </x-filament::button>
                </form>
            @endif
        </div>
    </div>
</x-filament-panels::page>
