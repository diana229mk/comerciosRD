<?php

namespace App\Filament\Widgets;

use App\Enums\ExpenseCategory;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Filament\Concerns\InteractsWithTenantDashboard;
use App\Filament\Pages\Pos;
use App\Models\Expense;
use App\Models\Order;
use App\Support\DashboardWidgetRegistry;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

class QuickActionsWidget extends Widget
{
    use InteractsWithTenantDashboard;

    protected static string $view = 'filament.widgets.quick-actions';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    protected static function requiredModules(): array
    {
        return ['sales', 'orders', 'expenses'];
    }

    protected static function widgetKey(): ?string
    {
        return DashboardWidgetRegistry::QUICK_ACTIONS;
    }

    /**
     * @return array<int, array{key: string, label: string, description: string, icon: string, color: string, action: string}>
     */
    public function actions(): array
    {
        return [
            [
                'key'         => 'new_order',
                'label'       => 'Tomar nueva orden',
                'description' => 'Por nombre o por número',
                'icon'        => 'heroicon-o-clipboard-document-list',
                'color'       => 'primary',
                'action'      => 'openNewOrder',
            ],
            [
                'key'         => 'quick_expense',
                'label'       => 'Registrar gasto rápido',
                'description' => 'Salida de caja inmediata',
                'icon'        => 'heroicon-o-banknotes',
                'color'       => 'warning',
                'action'      => 'openQuickExpense',
            ],
            [
                'key'         => 'pos',
                'label'       => 'Nueva venta (POS)',
                'description' => 'Abrir punto de venta',
                'icon'        => 'heroicon-o-shopping-cart',
                'color'       => 'success',
                'action'      => 'goToPos',
            ],
            [
                'key'         => 'pending_orders',
                'label'       => 'Ver órdenes pendientes',
                'description' => 'Cola actual del negocio',
                'icon'        => 'heroicon-o-queue-list',
                'color'       => 'info',
                'action'      => 'openPendingOrders',
            ],
        ];
    }

    public function goToPos(): void
    {
        $this->redirect(Pos::getUrl());
    }

    public function openNewOrder(): void
    {
        $this->dispatch('open-modal', id: 'quick-new-order');
    }

    public function openQuickExpense(): void
    {
        $this->dispatch('open-modal', id: 'quick-expense-widget');
    }

    public function openPendingOrders(): void
    {
        $this->dispatch('open-modal', id: 'pending-orders-list');
    }

    /**
     * @return list<Order>
     */
    public function getPendingOrdersProperty(): array
    {
        return Order::query()
            ->open()
            ->orderBy('created_at')
            ->limit(20)
            ->get()
            ->all();
    }

    public function createOrder(array $data): void
    {
        $business = $this->currentBusiness();
        if (! $business) {
            return;
        }

        DB::transaction(function () use ($data, $business) {
            Order::create([
                'created_by' => auth()->id(),
                'order_number' => Order::nextNumber($business->getKey()),
                'order_type' => $data['order_type'] ?? $data['type'] ?? OrderType::PorNumero->value,
                'customer_name' => $data['customer_name'] ?? null,
                'status' => OrderStatus::Pendiente,
                'notes' => $data['notes'] ?? null,
                'subtotal' => 0,
                'tax' => 0,
                'total' => 0,
            ]);
        });

        Notification::make()
            ->title('Orden creada')
            ->success()
            ->send();

        $this->dispatch('close-modal', id: 'quick-new-order');
    }

    public function createExpense(array $data): void
    {
        DB::transaction(function () use ($data): void {
            Expense::create([
                'user_id' => auth()->id(),
                'created_by' => auth()->id(),
                'category' => $data['category'],
                'description' => $data['description'],
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'] ?? PaymentMethod::Cash->value,
                'expense_date' => $data['expense_date'] ?? now()->toDateString(),
                'is_paid' => true,
            ]);
        });

        Notification::make()
            ->title('Gasto registrado')
            ->success()
            ->send();

        $this->dispatch('close-modal', id: 'quick-expense-widget');
    }

    /**
     * Esquemas de formularios para la vista Blade (Livewire + Filament forms vía wire).
     *
     * @return array<string, mixed>
     */
    public function getOrderFormSchema(): array
    {
        return [
            'order_type' => OrderType::PorNombre->value,
            'customer_name' => '',
            'notes' => '',
        ];
    }

    public function getExpenseFormSchema(): array
    {
        return [
            'category' => ExpenseCategory::Otros->value,
            'description'    => '',
            'amount'         => '',
            'payment_method' => PaymentMethod::Cash->value,
            'expense_date'   => now()->toDateString(),
        ];
    }

    /** @var array<string, mixed> */
    public array $orderForm = [];

    /** @var array<string, mixed> */
    public array $expenseForm = [];

    public function mount(): void
    {
        $this->orderForm = $this->getOrderFormSchema();
        $this->expenseForm = $this->getExpenseFormSchema();
    }

    public function submitOrder(): void
    {
        $this->validate([
            'orderForm.order_type' => 'required|in:por_nombre,por_numero',
            'orderForm.customer_name' => 'nullable|string|max:255',
            'orderForm.notes' => 'nullable|string|max:1000',
        ]);

        if ($this->orderForm['order_type'] === OrderType::PorNombre->value
            && blank($this->orderForm['customer_name'] ?? null)
        ) {
            Notification::make()
                ->title('Indica el nombre del cliente')
                ->danger()
                ->send();

            return;
        }

        $this->createOrder($this->orderForm);
        $this->orderForm = $this->getOrderFormSchema();
    }

    public function submitExpense(): void
    {
        $this->validate([
            'expenseForm.category'       => 'required|string',
            'expenseForm.description'    => 'required|string|max:255',
            'expenseForm.amount'         => 'required|numeric|min:0.01',
            'expenseForm.payment_method' => 'required|string',
            'expenseForm.expense_date'   => 'required|date',
        ]);

        $this->createExpense($this->expenseForm);
        $this->expenseForm = $this->getExpenseFormSchema();
    }

    public function orderTypeOptions(): array
    {
        return OrderType::options();
    }

    public function expenseCategoryOptions(): array
    {
        return ExpenseCategory::options();
    }

    public function paymentMethodOptions(): array
    {
        return PaymentMethod::options();
    }
}
