<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class CafeteriaDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-fire';

    protected static ?string $navigationLabel = 'Cocina';

    protected static ?string $title = 'Cocina';

    protected static ?string $navigationGroup = 'Cocina';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.cafeteria-dashboard';

    protected static ?string $slug = 'dashboard-cafeteria';

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();
        if (! $tenant instanceof Business || ! $user instanceof User) {
            return false;
        }
        if (! $tenant->isCafeteria()) {
            return false;
        }

        return $user->hasRoleInBusiness(['owner', 'admin', 'supervisor', 'cashier']);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $pending = Order::query()->where('status', 'pendiente')->count();
        $kitchen = Order::query()->where('status', 'en_preparacion')->count();
        $ready = Order::query()->where('status', 'lista')->count();

        $board = Order::query()
            ->with('items')
            ->whereIn('status', ['pendiente', 'en_preparacion', 'lista'])
            ->latest()
            ->limit(60)
            ->get()
            ->groupBy(function (Order $o): string {
                $status = $o->status;
                if ($status instanceof \App\Enums\OrderStatus) {
                    return $status->value;
                }

                return strtolower(trim((string) $status));
            });

        return compact(
            'pending',
            'kitchen',
            'ready',
            'board',
        );
    }

    public function startPreparing(string $orderId): void
    {
        $this->moveOrder($orderId, 'en_preparacion', 'Preparando');
    }

    public function markReady(string $orderId): void
    {
        $this->moveOrder($orderId, 'lista', 'Lista — ya sale en Salida', ['ready_at' => now()]);
    }

    public function sendOut(string $orderId): void
    {
        $this->moveOrder($orderId, 'completada', 'Salió / entregada', ['completed_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function moveOrder(string $orderId, string $status, string $okTitle, array $extra = []): void
    {
        $order = $this->findOpenOrder($orderId);
        if (! $order) {
            Notification::make()
                ->title('No se encontró la orden')
                ->body('Recarga la página de Cocina.')
                ->warning()
                ->send();

            return;
        }

        try {
            $order->update(array_merge(['status' => $status], $extra));
            Notification::make()->title($okTitle)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('No se pudo mover la orden')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    protected function findOpenOrder(string $orderId): ?Order
    {
        return Order::query()
            ->whereKey($orderId)
            ->whereIn('status', ['pendiente', 'en_preparacion', 'lista'])
            ->first();
    }
}
