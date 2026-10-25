<?php

namespace App\Filament\Master\Widgets;

use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionPlan;
use App\Filament\Master\Resources\BusinessResource;
use App\Models\Business;
use App\Models\SubscriptionInvoice;
use App\Services\SeatLimitService;
use App\Services\SubscriptionBillingService;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class SubscriptionManagerTable extends BaseWidget
{
    protected static ?string $heading = 'Locales y cuotas del mes';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $period = now()->startOfMonth()->toDateString();

        return $table
            ->query(
                Business::query()
                    ->with(['subscriptionInvoices' => fn ($q) => $q->whereDate('period_start', $period)])
                    ->orderByDesc('is_active')
                    ->orderBy('name')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Negocio')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Business $record) => $record->typeLabel),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
                Tables\Columns\TextColumn::make('subscription_plan')
                    ->label('Plan')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof SubscriptionPlan
                        ? $state->label()
                        : SubscriptionPlan::tryFromMixed($state)->label()),
                Tables\Columns\TextColumn::make('extra_seats')
                    ->label('Extra')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('monthly')
                    ->label('Cuota')
                    ->getStateUsing(function (Business $record): string {
                        $pay = app(SeatLimitService::class)->monthlyTotal($record);
                        if ($record->hasActivePromo()) {
                            return 'RD$ '.number_format($pay, 0).' (oferta '.$record->promoPercent().'%)';
                        }

                        return 'RD$ '.number_format($pay, 0);
                    }),
                Tables\Columns\TextColumn::make('invoice_status')
                    ->label('Este mes')
                    ->badge()
                    ->getStateUsing(function (Business $record): string {
                        $inv = $record->subscriptionInvoices->first();
                        if (! $inv) {
                            return $record->is_active ? 'Sin cuota' : '—';
                        }
                        $status = $inv->status;

                        return $status instanceof SubscriptionInvoiceStatus
                            ? $status->label()
                            : (string) $status;
                    })
                    ->color(function (Business $record): string {
                        $inv = $record->subscriptionInvoices->first();
                        if (! $inv) {
                            return $record->is_active ? 'gray' : 'gray';
                        }
                        $status = $inv->status;

                        return $status instanceof SubscriptionInvoiceStatus
                            ? $status->color()
                            : 'gray';
                    }),
                Tables\Columns\TextColumn::make('due_at')
                    ->label('Vence')
                    ->getStateUsing(function (Business $record): string {
                        $inv = $record->subscriptionInvoices->first();

                        return $inv?->due_at?->format('d/m/Y') ?? '—';
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('pagar')
                    ->label('Registrar pago')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(function (Business $record): bool {
                        $inv = $record->subscriptionInvoices->first();

                        return $inv instanceof SubscriptionInvoice && $inv->isOpen();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Registrar pago de suscripción')
                    ->modalDescription(fn (Business $record) => 'Se marca pagada la cuota de '.$record->name.' de este mes.')
                    ->action(function (Business $record): void {
                        $inv = $record->subscriptionInvoices->first();
                        if (! $inv instanceof SubscriptionInvoice) {
                            return;
                        }
                        app(SubscriptionBillingService::class)->markPaid($inv);
                        Notification::make()->title('Pago registrado')->success()->send();
                    }),
                Tables\Actions\Action::make('generar')
                    ->label('Crear cuota')
                    ->icon('heroicon-o-plus')
                    ->color('gray')
                    ->visible(fn (Business $record) => $record->is_active && $record->subscriptionInvoices->first() === null)
                    ->action(function (Business $record): void {
                        app(SubscriptionBillingService::class)->ensureCurrentInvoice($record);
                        Notification::make()->title('Cuota creada')->success()->send();
                    }),
                Tables\Actions\Action::make('plan')
                    ->label('Ajustar plan')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->url(fn (Business $record) => BusinessResource::getUrl('edit', ['record' => $record])),
            ])
            ->paginated([10, 25, 50]);
    }
}
