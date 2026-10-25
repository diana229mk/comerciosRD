<?php

namespace App\Filament\Resources;

use App\Enums\ExpenseCategory;
use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\ExpenseResource\Pages;
use App\Models\Expense;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ExpenseResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'expenses';

    protected static string $viewPermission = 'view_expenses';

    protected static string $createPermission = 'create_expenses';

    protected static string $editPermission = 'edit_expenses';

    protected static ?string $deletePermission = null;

    protected static ?string $model = Expense::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Gastos';

    protected static ?string $modelLabel = 'Gasto';

    protected static ?string $pluralModelLabel = 'Gastos';

    protected static ?string $navigationGroup = 'Finanzas';

    protected static ?int $navigationSort = 1;

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Gasto')
                    ->schema([
                        Forms\Components\Select::make('category')
                            ->label('Categoría')
                            ->options([
                                'alquiler' => 'Alquiler',
                                'luz' => 'Luz / Electricidad',
                                'agua' => 'Agua',
                                'internet' => 'Internet',
                                'sueldos' => 'Sueldos',
                                'transporte' => 'Transporte / Fletes',
                                'marketing' => 'Marketing / Publicidad',
                                'mantenimiento' => 'Mantenimiento',
                                'impuestos' => 'Impuestos',
                                'otros' => 'Otros',
                            ])
                            ->required()
                            ->searchable()
                            ->native(false),

                        Forms\Components\TextInput::make('description')
                            ->label('Descripción')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('amount')
                            ->label('Monto')
                            ->required()
                            ->numeric()
                            ->prefix('RD$')
                            ->minValue(0)
                            ->step(0.01),

                        Forms\Components\DatePicker::make('expense_date')
                            ->label('Fecha del Gasto')
                            ->required()
                            ->default(now())
                            ->native(false),

                        Forms\Components\Select::make('payment_method')
                            ->label('Método de Pago')
                            ->options([
                                'efectivo' => 'Efectivo',
                                'transferencia' => 'Transferencia Bancaria',
                                'tarjeta' => 'Tarjeta',
                                'cheque' => 'Cheque',
                            ])
                            ->default('efectivo')
                            ->required(),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notas / Observaciones')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('expense_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Categoría')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        $value = $state instanceof ExpenseCategory ? $state->value : (string) $state;

                        return match ($value) {
                            'alquiler' => 'Alquiler',
                            'luz' => 'Luz / Electricidad',
                            'agua' => 'Agua',
                            'internet' => 'Internet',
                            'sueldos' => 'Sueldos',
                            'transporte' => 'Transporte / Fletes',
                            'marketing' => 'Marketing / Publicidad',
                            'mantenimiento' => 'Mantenimiento',
                            'impuestos' => 'Impuestos',
                            'otros' => 'Otros',
                            default => $value,
                        };
                    })
                    ->color(function ($state): string {
                        $value = $state instanceof ExpenseCategory ? $state->value : (string) $state;

                        return match ($value) {
                            'alquiler' => 'info',
                            'sueldos' => 'warning',
                            'luz', 'agua', 'internet' => 'success',
                            default => 'gray',
                        };
                    })
                    ->searchable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Descripción')
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Monto')
                    ->money('DOP')
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Método de Pago')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        $value = (string) $state;

                        return match ($value) {
                            'efectivo', 'cash' => 'Efectivo',
                            'transferencia', 'transfer' => 'Transferencia Bancaria',
                            'tarjeta', 'card' => 'Tarjeta',
                            'cheque' => 'Cheque',
                            default => $value,
                        };
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Registrado por')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),

                \App\Filament\Support\BranchTableHelpers::column(),
            ])
            ->defaultSort('expense_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Categoría')
                    ->options([
                        'alquiler' => 'Alquiler',
                        'luz' => 'Luz',
                        'sueldos' => 'Sueldos',
                        'otros' => 'Otros',
                    ]),

                \App\Filament\Support\BranchTableHelpers::filter(),

                Tables\Filters\Filter::make('expense_date')
                    ->form([
                        Forms\Components\DatePicker::make('desde')->label('Desde'),
                        Forms\Components\DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['desde'] ?? null,
                                fn ($q, $date) => $q->whereDate('expense_date', '>=', $date)
                            )
                            ->when(
                                $data['hasta'] ?? null,
                                fn ($q, $date) => $q->whereDate('expense_date', '<=', $date)
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No hay gastos registrados')
            ->emptyStateDescription('Registra los gastos de tu negocio para llevar un mejor control.');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // El Global Scope del trait BelongsToBusiness ya filtra por tenant
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function canViewAny(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        // Tabla: supervisor, admin, owner (viewer solo lectura si se habilita en config)
        return auth()->user()?->canPerform('view_expenses') ?? false;
    }

    public static function canCreate(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin', 'supervisor']) ?? false;
    }

    public static function canEdit($record): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin', 'supervisor']) ?? false;
    }

    public static function canDelete($record): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }

}
