<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class CafeteriaPickup extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-right-on-rectangle';

    protected static ?string $navigationLabel = 'Salida';

    protected static ?string $title = 'Ventana de salida';

    protected static ?string $navigationGroup = 'Cocina';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.cafeteria-pickup';

    protected static ?string $slug = 'cocina-salida';

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
     * @return \Illuminate\Support\Collection<int, Order>
     */
    public function readyOrders()
    {
        return Order::query()
            ->with('items')
            ->where('status', 'lista')
            ->orderBy('ready_at')
            ->orderBy('created_at')
            ->limit(40)
            ->get();
    }

    public function sendOut(string $orderId): void
    {
        $order = Order::query()
            ->whereKey($orderId)
            ->where('status', 'lista')
            ->first();

        if (! $order) {
            return;
        }

        $order->update([
            'status' => 'completada',
            'completed_at' => now(),
        ]);

        Notification::make()->title('Entregada · '.$order->order_number)->success()->send();
    }
}
