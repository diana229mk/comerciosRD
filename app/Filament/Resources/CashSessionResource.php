<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\CashSessionResource\Pages;
use App\Models\CashSession;
use App\Services\CashSessionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CashSessionResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'cash_register';

    protected static string $viewPermission = 'view_cash';

    protected static string $createPermission = 'open_cash';

    protected static ?string $editPermission = 'close_cash';

    protected static ?string $deletePermission = null;

    /** Visible en menú del cajero */
    protected static bool $cashierNavigation = true;

    protected static ?string $model = CashSession::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Caja / Arqueo';

    protected static ?string $modelLabel = 'Sesión de Caja';

    protected static ?string $pluralModelLabel = 'Sesiones de Caja';

    protected static ?string $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 4;

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Apertura de Caja')
                    ->schema([
                        Forms\Components\TextInput::make('opening_amount')
                            ->label('Monto de Apertura')
                            ->numeric()
                            ->prefix('RD$')
                            ->required()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Dinero con el que inicia el turno'),

                        Forms\Components\Textarea::make('opening_notes')
                            ->label('Notas de Apertura')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (string $operation): bool => $operation === 'create'),

                Forms\Components\Section::make('Información de la Sesión')
                    ->schema([
                        Forms\Components\Placeholder::make('opened_by')
                            ->label('Abierta por')
                            ->content(fn (?CashSession $record): string => $record?->user?->name ?? '—'),

                        Forms\Components\Placeholder::make('opened_at_display')
                            ->label('Fecha de Apertura')
                            ->content(fn (?CashSession $record): string => $record?->opened_at?->format('d/m/Y H:i') ?? '—'),

                        Forms\Components\Placeholder::make('opening_amount_display')
                            ->label('Monto de Apertura')
                            ->content(fn (?CashSession $record): string => 'RD$ '.number_format((float) ($record?->opening_amount ?? 0), 2)),

                        Forms\Components\Placeholder::make('total_sales_cash_display')
                            ->label('Ventas en Efectivo')
                            ->content(function (?CashSession $record): string {
                                if (! $record) {
                                    return 'RD$ 0.00';
                                }
                                if ($record->isOpen()) {
                                    $p = app(CashSessionService::class)->preview($record);

                                    return 'RD$ '.number_format($p['sales'], 2);
                                }

                                return 'RD$ '.number_format((float) $record->total_sales_cash, 2);
                            }),

                        Forms\Components\Placeholder::make('total_expenses_cash_display')
                            ->label('Gastos en Efectivo')
                            ->content(function (?CashSession $record): string {
                                if (! $record) {
                                    return 'RD$ 0.00';
                                }
                                if ($record->isOpen()) {
                                    $p = app(CashSessionService::class)->preview($record);

                                    return 'RD$ '.number_format($p['expenses'], 2);
                                }

                                return 'RD$ '.number_format((float) $record->total_expenses_cash, 2);
                            }),

                        Forms\Components\Placeholder::make('expected_amount_display')
                            ->label('Monto Esperado')
                            ->content(function (?CashSession $record): string {
                                if (! $record) {
                                    return 'RD$ 0.00';
                                }
                                if ($record->isOpen()) {
                                    $p = app(CashSessionService::class)->preview($record);

                                    return 'RD$ '.number_format($p['expected'], 2);
                                }

                                return 'RD$ '.number_format((float) ($record->expected_amount ?? $record->calculateExpectedAmount()), 2);
                            }),
                    ])
                    ->columns(2)
                    ->visible(fn (string $operation): bool => $operation === 'edit'),

                Forms\Components\Section::make('Cierre de Caja (Arqueo)')
                    ->schema([
                        Forms\Components\TextInput::make('closing_amount')
                            ->label('Monto Real Contado')
                            ->numeric()
                            ->prefix('RD$')
                            ->required()
                            ->minValue(0)
                            ->helperText('Cuenta el dinero físico y escríbelo aquí'),

                        Forms\Components\Textarea::make('closing_notes')
                            ->label('Notas de Cierre')
                            ->rows(2)
                            ->columnSpanFull()
                            ->placeholder('Observaciones del arqueo (sobrante, faltante, etc.)'),
                    ])
                    ->visible(fn (string $operation, ?CashSession $record): bool => $operation === 'edit'
                        && $record?->status === 'open'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('opened_at')
                    ->label('Fecha Apertura')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Abierta por')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('opening_amount')
                    ->label('Apertura')
                    ->money('DOP'),

                Tables\Columns\TextColumn::make('total_sales_cash')
                    ->label('Ventas Efectivo')
                    ->money('DOP')
                    ->color('success'),

                Tables\Columns\TextColumn::make('closing_amount')
                    ->label('Cierre')
                    ->money('DOP')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('difference')
                    ->label('Diferencia')
                    ->money('DOP')
                    ->color(fn ($state): string => match (true) {
                        $state === null => 'gray',
                        (float) $state > 0 => 'success',
                        (float) $state < 0 => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->colors([
                        'success' => 'open',
                        'gray' => 'closed',
                    ])
                    ->formatStateUsing(fn (string $state): string => $state === 'open' ? 'Abierta' : 'Cerrada'),

                \App\Filament\Support\BranchTableHelpers::column(),
            ])
            ->defaultSort('opened_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'open' => 'Abierta',
                        'closed' => 'Cerrada',
                    ]),
                \App\Filament\Support\BranchTableHelpers::filter(),
            ])
            ->actions([
                Tables\Actions\Action::make('cerrar_caja')
                    ->label('Cerrar Caja')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->visible(fn (CashSession $record): bool => static::canEdit($record))
                    ->url(fn (CashSession $record): string => static::getUrl('edit', ['record' => $record])),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (CashSession $record): bool => static::canEdit($record)),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Sin sesiones de caja')
            ->emptyStateDescription('Abre la caja al inicio del turno con el fondo inicial.');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashSessions::route('/'),
            'create' => Pages\CreateCashSession::route('/create'),
            'edit' => Pages\EditCashSession::route('/{record}/edit'),
            'view' => Pages\ViewCashSession::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'closedByUser']);
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = filament()->getTenant();

        return $tenant ? $tenant->hasModule('cash_register') && static::canViewAny() : false;
    }

    public static function canViewAny(): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('cash_register')) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin', 'supervisor', 'cashier']) ?? false;
    }

    public static function canCreate(): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('cash_register')) {
            return false;
        }

        if (! (auth()->user()?->hasRoleInBusiness(['owner', 'admin', 'supervisor', 'cashier']) ?? false)) {
            return false;
        }

        // Un turno abierto por cajero (otros pueden tener el suyo)
        $userId = auth()->id();

        return ! CashSession::query()->open()->when(
            $userId,
            fn ($q) => $q->where('user_id', $userId),
        )->exists();
    }

    public static function canEdit($record): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('cash_register')) {
            return false;
        }

        // Solo se puede editar (cerrar) si la caja está abierta
        $isOpen = $record instanceof CashSession
            ? $record->isOpen()
            : (is_object($record) && ($record->status ?? null) === CashSession::STATUS_OPEN);

        if (! $isOpen) {
            return false;
        }

        $user = auth()->user();
        if (! $user?->hasRoleInBusiness(['owner', 'admin', 'supervisor', 'cashier'])) {
            return false;
        }

        if ($user->isStrictCashier()) {
            $recordUserId = $record instanceof CashSession
                ? $record->user_id
                : (is_object($record) ? ($record->user_id ?? null) : null);

            return (string) $recordUserId === (string) $user->getKey();
        }

        return true;
    }

    public static function canDelete($record): bool
    {
        // No se deben eliminar sesiones de caja por seguridad
        return false;
    }
}
