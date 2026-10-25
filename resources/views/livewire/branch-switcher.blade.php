<div class="fi-topbar-item flex items-center gap-2 px-2 max-w-[min(100%,28rem)]">
    @if ($businessName || $userName)
        <div
            class="flex min-w-0 items-center gap-1.5 rounded-lg bg-gray-50 dark:bg-gray-800 px-2.5 py-1 border border-gray-200 dark:border-gray-700 text-xs text-gray-700 dark:text-gray-200"
            title="{{ trim(($businessName ?? '').' · '.($currentBranch?->name ?? '').' · '.($userName ?? '').($roleLabel ? ' ('.$roleLabel.')' : '')) }}"
        >
            <x-heroicon-o-building-storefront class="h-3.5 w-3.5 shrink-0 text-gray-500 dark:text-gray-400" />
            <span class="truncate font-semibold">{{ $businessName }}</span>
            @if ($userName)
                <span class="text-gray-400 dark:text-gray-500 shrink-0">·</span>
                <span class="truncate">{{ $userName }}@if ($roleLabel && $roleLabel !== '—') <span class="text-gray-500 dark:text-gray-400">({{ $roleLabel }})</span>@endif</span>
            @endif
        </div>
    @endif

    @if ($canSwitch)
        <label for="branch-switcher" class="sr-only">Sucursal activa</label>
        <div class="flex items-center gap-2 rounded-lg bg-gray-50 dark:bg-gray-800 px-2 py-1 border border-gray-200 dark:border-gray-700">
            <x-heroicon-o-map-pin class="h-4 w-4 text-gray-500 dark:text-gray-400" />
            <select
                id="branch-switcher"
                wire:model.live="branchId"
                class="border-0 bg-transparent text-sm text-gray-900 dark:text-gray-100 focus:ring-0 focus:outline-none py-0 pr-6 pl-1 cursor-pointer"
            >
                <option value="">Dashboard completo (todas las sucursales)</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}">
                        {{ $branch->name }}@if ($branch->code) ({{ $branch->code }})@endif
                    </option>
                @endforeach
            </select>
        </div>
    @elseif ($currentBranch)
        <div
            class="flex items-center gap-1.5 rounded-full bg-primary-50 dark:bg-primary-950/50 px-2.5 py-1 text-xs font-medium text-primary-700 dark:text-primary-300 border border-primary-200 dark:border-primary-800"
            title="Sucursal actual"
        >
            <x-heroicon-o-map-pin class="h-3.5 w-3.5" />
            {{ $currentBranch->name }}
        </div>
    @endif
</div>
