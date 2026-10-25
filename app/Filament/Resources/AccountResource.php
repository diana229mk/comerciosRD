<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\AccountResource\Pages;
use App\Models\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\Rules\Unique;

class AccountResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'accounting';

    protected static string $viewPermission = 'view_accounts';

    protected static string $createPermission = 'create_accounts';

    protected static string $editPermission = 'edit_accounts';

    protected static ?string $deletePermission = 'delete_accounts';

    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Plan de Cuentas';

    protected static ?string $modelLabel = 'Cuenta Contable';

    protected static ?string $pluralModelLabel = 'Plan de Cuentas';

    protected static ?string $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información de la Cuenta')
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->label('Código')
                            ->required()
                            ->maxLength(20)
                            ->placeholder('Ej: 1101, 4101, 5101')
                            ->unique(
                                table: Account::class,
                                column: 'code',
                                ignoreRecord: true,
                                modifyRuleUsing: function (Unique $rule) {
                                    $tenantId = filament()->getTenant()?->getKey();

                                    return $rule->where('business_id', $tenantId);
                                },
                            )
                            ->helperText('Único por negocio. 1xxx Activo, 2xxx Pasivo, 3xxx Capital, 4xxx Ingreso, 5xxx Costo, 6xxx Gasto.'),

                        Forms\Components\TextInput::make('name')
                            ->label('Nombre de la Cuenta')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Select::make('type')
                            ->label('Tipo de Cuenta')
                            ->options(Account::types())
                            ->required()
                            ->native(false)
                            ->disabled(fn (?Account $record): bool => (bool) ($record?->is_system)),

                        Forms\Components\Textarea::make('description')
                            ->label('Descripción')
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Activa')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->colors([
                        'success' => 'asset',
                        'danger' => 'liability',
                        'warning' => 'equity',
                        'info' => 'income',
                        'gray' => 'expense',
                        'primary' => 'cost',
                    ])
                    ->formatStateUsing(fn (?string $state): string => Account::types()[$state] ?? (string) $state)
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_system')
                    ->label('Sistema')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->defaultSort('code')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(Account::types()),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->trueLabel('Solo activas')
                    ->falseLabel('Solo inactivas'),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn (Account $record): bool => static::canEdit($record)
                        && ! $record->is_system),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Account $record): bool => static::canDelete($record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function ($records): void {
                            $records->each(function (Account $account): void {
                                if (! $account->is_system) {
                                    $account->delete();
                                }
                            });
                        }),
                ]),
            ])
            ->emptyStateHeading('Sin plan de cuentas')
            ->emptyStateDescription('Carga el plan básico del sistema o crea la primera cuenta.')
            ->emptyStateActions([
                Tables\Actions\Action::make('seed')
                    ->label('Cargar plan básico')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->action(function (): void {
                        $tenant = filament()->getTenant();
                        if ($tenant) {
                            \App\Services\AccountingService::createDefaultAccounts($tenant);
                        }
                    })
                    ->visible(fn (): bool => static::canCreate()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
            'create' => Pages\CreateAccount::route('/create'),
            'edit' => Pages\EditAccount::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = filament()->getTenant();

        return $tenant ? $tenant->hasModule('accounting') && static::canViewAny() : false;
    }

    public static function canViewAny(): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('accounting')) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('accounting')) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }

    public static function canEdit($record): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('accounting')) {
            return false;
        }

        // No se pueden editar cuentas del sistema
        if ($record instanceof Account && $record->is_system) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }

    public static function canDelete($record): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('accounting')) {
            return false;
        }

        // No se pueden eliminar cuentas del sistema
        if ($record instanceof Account && $record->is_system) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }
}
