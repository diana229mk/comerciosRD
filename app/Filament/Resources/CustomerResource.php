<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CustomerResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'customers';

    protected static string $viewPermission = 'view_customers';
    protected static string $createPermission = 'create_customers';
    protected static string $editPermission = 'edit_customers';
    protected static ?string $deletePermission = null;

    /** Visible en menú del cajero (fiado) */
    protected static bool $cashierNavigation = true;

    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Clientes';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clientes';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return match (filament()->getTenant()?->business_type) {
            'colmado' => 'Clientes / Fiado',
            'distribuidora', 'almacen' => 'Clientes Mayoreo',
            'ferreteria' => 'Clientes / Crédito',
            'repuestos' => 'Clientes / Talleres',
            default => 'Clientes',
        };
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Ventas';
    }

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Cliente')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre Completo')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('phone')
                            ->label('Teléfono')
                            ->tel()
                            ->maxLength(30)
                            ->placeholder('809-555-0000'),

                        Forms\Components\TextInput::make('email')
                            ->label('Correo Electrónico')
                            ->email()
                            ->maxLength(255),

                        Forms\Components\Select::make('document_type')
                            ->label('Tipo de documento')
                            ->options(Customer::documentTypeOptions())
                            ->default('cedula')
                            ->native(false),

                        // Columna real: document (alias identification en el modelo)
                        Forms\Components\TextInput::make('document')
                            ->label('Cédula / RNC')
                            ->maxLength(40),

                        Forms\Components\TextInput::make('city')
                            ->label('Ciudad')
                            ->maxLength(100),

                        Forms\Components\Textarea::make('address')
                            ->label('Dirección')
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notas / Observaciones')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Crédito y estado')
                    ->schema([
                        Forms\Components\TextInput::make('credit_limit')
                            ->label('Límite de crédito')
                            ->numeric()
                            ->prefix('RD$')
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Útil para ventas a crédito y cuentas por cobrar'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Cliente Activo')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Teléfono')
                    ->searchable()
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('document')
                    ->label('Cédula / RNC')
                    ->searchable()
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('city')
                    ->label('Ciudad')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('credit_limit')
                    ->label('Crédito')
                    ->money('DOP')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('receivables_count')
                    ->label('Cuentas por Cobrar')
                    ->counts('receivables')
                    ->badge()
                    ->color(fn ($state) => (int) $state > 0 ? 'warning' : 'gray'),

                Tables\Columns\TextColumn::make('sales_count')
                    ->label('Ventas')
                    ->counts('sales')
                    ->badge()
                    ->color('primary')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),

                Tables\Filters\Filter::make('con_deuda')
                    ->label('Con Cuentas por Cobrar')
                    ->query(fn (Builder $query): Builder => $query->whereHas('receivables', function (Builder $q): void {
                        $q->whereIn('status', ['pendiente', 'parcial', 'vencida']);
                    }))
                    ->toggle(),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(fn (): string => match (filament()->getTenant()?->business_type) {
                'repuestos' => 'Sin talleres / clientes',
                'cafeteria' => 'Sin clientes',
                default => 'Sin clientes',
            })
            ->emptyStateDescription(fn (): string => match (filament()->getTenant()?->business_type) {
                'repuestos' => 'Registra talleres y clientes para cotizaciones y preventa. El cobro lo hace caja.',
                'colmado' => 'Registra clientes para fiado y cuentas por cobrar.',
                'cafeteria' => 'Opcional: nombre en la orden o ficha para cuentas frecuentes.',
                default => 'Registra clientes para cotizar, vender a crédito o facturar.',
            })
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()
                    ->label(fn (): string => match (filament()->getTenant()?->business_type) {
                        'repuestos' => 'Nuevo taller / cliente',
                        default => 'Nuevo cliente',
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::query()->count();
    }

    public static function canViewAny(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->canPerform('view_customers') ?? false;
    }

    public static function canCreate(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->canPerform('create_customers') ?? false;
    }

    public static function canEdit($record): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->canPerform('edit_customers') ?? false;
    }

    public static function canDelete($record): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }

}
