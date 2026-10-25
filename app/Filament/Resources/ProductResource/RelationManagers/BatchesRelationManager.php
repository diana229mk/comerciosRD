<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBatch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gestión de lotes (uso principal: farmacia).
 * Cada lote tiene fecha de vencimiento y cantidad. La venta descuenta por FEFO.
 */
class BatchesRelationManager extends RelationManager
{
    use Concerns\LoadsEagerly;

    protected static string $relationship = 'batches';

    protected static ?string $title = 'Lotes';

    protected static ?string $modelLabel = 'Lote';

    protected static ?string $pluralModelLabel = 'Lotes';

    protected static ?string $icon = 'heroicon-o-archive-box';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        $tenant = filament()->getTenant();
        if ($tenant instanceof Business) {
            return $tenant->isPharmacy();
        }

        if ($ownerRecord instanceof Product) {
            $business = $ownerRecord->relationLoaded('business')
                ? $ownerRecord->business
                : Business::query()->find($ownerRecord->business_id);

            return $business instanceof Business && $business->isPharmacy();
        }

        return false;
    }

    public function getRelationship(): \Illuminate\Database\Eloquent\Relations\Relation|\Illuminate\Database\Eloquent\Builder
    {
        try {
            $owner = $this->getOwnerRecord();
            if ($owner instanceof Product) {
                $rel = $owner->batches();
                if ($rel->getQuery()->getModel() !== null) {
                    return $rel;
                }
            }
        } catch (\Throwable) {
        }

        return ProductBatch::query()->whereRaw('1 = 0');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')
                ->label('Código de lote')
                ->maxLength(60)
                ->placeholder('Ej: L2026-08-A')
                ->helperText('Opcional pero recomendado para trazabilidad.'),

            Forms\Components\DatePicker::make('expires_at')
                ->label('Fecha de vencimiento')
                ->native(false)
                ->required()
                ->minDate(now()->subYears(2))
                ->helperText('Los lotes vencidos se bloquean automáticamente en el POS.'),

            Forms\Components\DatePicker::make('received_at')
                ->label('Fecha de recepción')
                ->native(false)
                ->default(now()),

            Forms\Components\TextInput::make('quantity')
                ->label('Cantidad disponible')
                ->numeric()
                ->minValue(0)
                ->step('0.001')
                ->required()
                ->default(0),

            Forms\Components\TextInput::make('initial_quantity')
                ->label('Cantidad inicial recibida')
                ->numeric()
                ->minValue(0)
                ->step('0.001')
                ->helperText('Se registra al crear el lote (auditoría).'),

            Forms\Components\TextInput::make('cost')
                ->label('Costo unitario del lote')
                ->numeric()
                ->minValue(0)
                ->step('0.01')
                ->prefix('RD$'),

            Forms\Components\Toggle::make('is_blocked')
                ->label('Lote bloqueado')
                ->helperText('Bloquea la venta manualmente (ej. retiro sanitario).'),

            Forms\Components\Textarea::make('notes')
                ->label('Notas')
                ->rows(2)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->modifyQueryUsing(function (Builder $query): void {
                $query
                    ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('expires_at')
                    ->orderBy('created_at');
            })
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Lote')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn ($state, ?ProductBatch $record): string => match (true) {
                        $record?->isExpired() => 'danger',
                        $record?->isNearExpiry(30) => 'warning',
                        default => 'success',
                    })
                    ->badge()
                    ->formatStateUsing(function ($state, ?ProductBatch $record) {
                        if (! $state) {
                            return '—';
                        }
                        $date = $state instanceof \Carbon\CarbonInterface
                            ? $state
                            : \Carbon\Carbon::parse($state);
                        $days = $record?->daysToExpire() ?? 0;

                        if ($record?->isExpired()) {
                            return $date->format('d/m/Y').' · vencido';
                        }

                        return $date->format('d/m/Y')." · {$days}d";
                    }),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                Tables\Columns\TextColumn::make('initial_quantity')
                    ->label('Recibido')
                    ->numeric(decimalPlaces: 3)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('cost')
                    ->label('Costo')
                    ->money('DOP')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_blocked')
                    ->label('Bloqueado')
                    ->boolean()
                    ->trueIcon('heroicon-m-lock-closed')
                    ->falseIcon('heroicon-m-lock-open')
                    ->trueColor('danger')
                    ->falseColor('success'),

                Tables\Columns\TextColumn::make('sale_allocations_count')
                    ->label('Ventas')
                    ->counts('saleAllocations')
                    ->badge()
                    ->color('info')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('received_at')
                    ->label('Recibido')
                    ->date('d/m/Y')
                    ->toggleable(isToggledHiddenByDefault: true),

                \App\Filament\Support\BranchTableHelpers::column(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_blocked')
                    ->label('Bloqueados'),
                Tables\Filters\Filter::make('expired')
                    ->label('Vencidos')
                    ->query(fn (Builder $q) => $q->expired()),
                Tables\Filters\Filter::make('expiring')
                    ->label('Próximos a vencer (30d)')
                    ->query(fn (Builder $q) => $q->expiringSoon(30)),
                \App\Filament\Support\BranchTableHelpers::filter(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Registrar lote')
                    ->mutateFormDataUsing(function (array $data): array {
                        // Si no se especifica initial_quantity, iguala a quantity
                        if (empty($data['initial_quantity']) || (float) $data['initial_quantity'] <= 0) {
                            $data['initial_quantity'] = $data['quantity'] ?? 0;
                        }
                        $data['created_by'] = auth()->id();

                        return $data;
                    })
                    ->after(function (ProductBatch $record) {
                        $this->syncProductStockFromBatches($record->product);

                        Notification::make()
                            ->title('Lote registrado')
                            ->body('Se actualizó el stock del producto.')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->after(function (ProductBatch $record) {
                        $this->syncProductStockFromBatches($record->product);
                    }),
                Tables\Actions\Action::make('toggle_block')
                    ->label(fn (ProductBatch $r) => $r->is_blocked ? 'Desbloquear' : 'Bloquear')
                    ->icon(fn (ProductBatch $r) => $r->is_blocked ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                    ->color(fn (ProductBatch $r) => $r->is_blocked ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->action(function (ProductBatch $record) {
                        $record->is_blocked = ! $record->is_blocked;
                        $record->save();
                    }),
                Tables\Actions\DeleteAction::make()
                    ->after(function (ProductBatch $record) {
                        $this->syncProductStockFromBatches($record->product);
                    }),
            ])
            ->defaultSort('expires_at')
            ->emptyStateHeading('Sin lotes registrados')
            ->emptyStateDescription('Registra cada compra como un lote con su fecha de vencimiento para vender por FEFO.')
            ->emptyStateIcon('heroicon-o-archive-box');
    }

    /**
     * Recalcula el stock del producto como la suma de cantidades de sus lotes activos.
     */
    protected function syncProductStockFromBatches(Product $product): void
    {
        $total = (float) $product->batches()->sum('quantity');
        $product->forceFill(['stock' => max(0, round($total, 3))])->saveQuietly();
    }
}
