<?php

namespace App\Filament\Master\Widgets;

use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionPlan;
use App\Filament\Master\Resources\SubscriptionInvoiceResource;
use App\Models\SubscriptionInvoice;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class UpcomingSubscriptionPayments extends BaseWidget
{
    protected static ?string $heading = 'Cuotas por cobrar';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                SubscriptionInvoice::query()
                    ->with('business')
                    ->open()
                    ->orderBy('due_at')
                    ->limit(15)
            )
            ->columns([
                Tables\Columns\TextColumn::make('business.name')
                    ->label('Negocio')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('plan')
                    ->label('Plan')
                    ->formatStateUsing(fn ($state) => $state instanceof SubscriptionPlan
                        ? $state->label()
                        : (string) $state)
                    ->badge(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Monto')
                    ->formatStateUsing(fn ($state) => 'RD$ '.number_format((int) $state, 0)),
                Tables\Columns\TextColumn::make('due_at')
                    ->label('Vence')
                    ->date('d/m/Y'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof SubscriptionInvoiceStatus
                        ? $state->label()
                        : (string) $state)
                    ->color(fn ($state) => $state instanceof SubscriptionInvoiceStatus
                        ? $state->color()
                        : 'gray'),
            ])
            ->actions([
                Tables\Actions\Action::make('pagar')
                    ->label('Marcar pagada')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (SubscriptionInvoice $record): void {
                        app(\App\Services\SubscriptionBillingService::class)->markPaid($record);
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nada pendiente')
            ->emptyStateDescription('Cuando un negocio tenga cuota del mes, aparece aquí.')
            ->recordUrl(fn (SubscriptionInvoice $record) => SubscriptionInvoiceResource::getUrl('edit', ['record' => $record]));
    }
}
