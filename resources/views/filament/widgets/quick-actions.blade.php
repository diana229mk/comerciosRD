<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Acciones rápidas
        </x-slot>
        <x-slot name="description">
            Atajos del día a día · {{ $this->currentBusiness()?->name }}
        </x-slot>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($this->actions() as $action)
                <button
                    type="button"
                    wire:click="{{ $action['action'] }}"
                    class="group flex w-full flex-col items-start gap-3 rounded-xl border border-gray-200 bg-white p-4 text-left shadow-sm transition hover:border-primary-400 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:hover:border-primary-500"
                >
                    <div @class([
                        'flex h-11 w-11 items-center justify-center rounded-lg',
                        'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400' => $action['color'] === 'primary',
                        'bg-warning-50 text-warning-600 dark:bg-warning-500/10 dark:text-warning-400' => $action['color'] === 'warning',
                        'bg-success-50 text-success-600 dark:bg-success-500/10 dark:text-success-400' => $action['color'] === 'success',
                        'bg-info-50 text-info-600 dark:bg-info-500/10 dark:text-info-400' => $action['color'] === 'info',
                    ])>
                        <x-filament::icon :icon="$action['icon']" class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $action['label'] }}
                        </div>
                        <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $action['description'] }}
                        </div>
                    </div>
                </button>
            @endforeach
        </div>
    </x-filament::section>

    {{-- Modal: nueva orden --}}
    <x-filament::modal id="quick-new-order" width="md" sticky-header sticky-footer>
        <x-slot name="heading">Tomar nueva orden</x-slot>
        <x-slot name="description">Elige si es por nombre del cliente o por número de orden.</x-slot>

        <div class="space-y-4">
            <div>
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <span class="text-sm font-medium text-gray-950 dark:text-white">Tipo de orden</span>
                </label>
                <select
                    wire:model.live="orderForm.order_type"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                >
                    @foreach ($this->orderTypeOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div x-show="$wire.orderForm.order_type === 'por_nombre'" x-cloak>
                <label class="text-sm font-medium text-gray-950 dark:text-white">Nombre del cliente</label>
                <input
                    type="text"
                    wire:model="orderForm.customer_name"
                    placeholder="Ej. Juan Pérez"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                />
            </div>

            <div>
                <label class="text-sm font-medium text-gray-950 dark:text-white">Notas</label>
                <textarea
                    wire:model="orderForm.notes"
                    rows="2"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                    placeholder="Opcional"
                ></textarea>
            </div>
        </div>

        <x-slot name="footerActions">
            <x-filament::button color="gray" x-on:click="close">Cancelar</x-filament::button>
            <x-filament::button wire:click="submitOrder" color="primary">
                Crear orden
            </x-filament::button>
        </x-slot>
    </x-filament::modal>

    {{-- Modal: gasto rápido --}}
    <x-filament::modal id="quick-expense-widget" width="md" sticky-header sticky-footer>
        <x-slot name="heading">Registrar gasto rápido</x-slot>

        <div class="space-y-4">
            <div>
                <label class="text-sm font-medium text-gray-950 dark:text-white">Categoría</label>
                <select
                    wire:model="expenseForm.category"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                >
                    @foreach ($this->expenseCategoryOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-medium text-gray-950 dark:text-white">Descripción</label>
                <input type="text" wire:model="expenseForm.description" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-sm font-medium text-gray-950 dark:text-white">Monto (RD$)</label>
                    <input type="number" step="0.01" min="0" wire:model="expenseForm.amount" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-950 dark:text-white">Fecha</label>
                    <input type="date" wire:model="expenseForm.expense_date" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                </div>
            </div>
            <div>
                <label class="text-sm font-medium text-gray-950 dark:text-white">Método de pago</label>
                <select
                    wire:model="expenseForm.payment_method"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                >
                    @foreach ($this->paymentMethodOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <x-slot name="footerActions">
            <x-filament::button color="gray" x-on:click="close">Cancelar</x-filament::button>
            <x-filament::button wire:click="submitExpense" color="warning">
                Guardar gasto
            </x-filament::button>
        </x-slot>
    </x-filament::modal>

    {{-- Modal: órdenes pendientes --}}
    <x-filament::modal id="pending-orders-list" width="lg" sticky-header>
        <x-slot name="heading">Órdenes pendientes</x-slot>
        <x-slot name="description">Cola abierta de este negocio (aislada por tenant).</x-slot>

        @php $orders = $this->pendingOrders; @endphp

        @if (count($orders) === 0)
            <div class="rounded-lg bg-gray-50 p-6 text-center text-sm text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                No hay órdenes pendientes. ¡Buen ritmo!
            </div>
        @else
            <div class="divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                @foreach ($orders as $order)
                    <div class="flex items-center justify-between gap-3 bg-white px-4 py-3 dark:bg-gray-900">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $order->displayLabel() }}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $order->order_number }} · {{ $order->typeLabel() }} · {{ $order->statusLabel() }}
                            </div>
                        </div>
                        <div class="shrink-0 text-xs text-gray-400">
                            {{ $order->created_at?->diffForHumans() }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::modal>
</x-filament-widgets::widget>
