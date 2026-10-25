<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SupplierResource extends Resource
{
    use \App\Filament\Concerns\ChecksBusinessModule;

    protected static ?string $model = Supplier::class;

    protected static string $businessModule = 'suppliers';

    protected static string $viewPermission = 'view_suppliers';

    protected static string $createPermission = 'create_suppliers';

    protected static string $editPermission = 'edit_suppliers';

    protected static ?string $deletePermission = null;


    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Proveedores';

    protected static ?string $modelLabel = 'Proveedor';

    protected static ?string $pluralModelLabel = 'Proveedores';

    protected static ?string $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 1;

    /** Ownership multi-tenant (Filament tenant → business) */
    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Proveedor')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre del Proveedor')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('contact_name')
                            ->label('Nombre de Contacto')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->label('Teléfono')
                            ->tel()
                            ->maxLength(20),

                        Forms\Components\TextInput::make('email')
                            ->label('Correo Electrónico')
                            ->email()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('rnc')
                            ->label('RNC')
                            ->maxLength(20),

                        Forms\Components\TextInput::make('city')
                            ->label('Ciudad')
                            ->maxLength(100),

                        Forms\Components\Textarea::make('address')
                            ->label('Dirección')
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notas')
                            ->rows(3)
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Activo')
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
                    ->label('Proveedor')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('contact_name')
                    ->label('Contacto')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Teléfono')
                    ->searchable(),

                Tables\Columns\TextColumn::make('rnc')
                    ->label('RNC')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('city')
                    ->label('Ciudad')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),

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
                    ->placeholder('Todos')
                    ->trueLabel('Solo activos')
                    ->falseLabel('Solo inactivos'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
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

        return auth()->user()?->canPerform('view_suppliers') ?? false;
    }

    public static function canCreate(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        // Tabla: supervisor, admin, owner
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
