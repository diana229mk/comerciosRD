<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\ProductCategoryResource\Pages;
use App\Models\ProductCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

class ProductCategoryResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'categories';

    /** Ver categorías: quien pueda ver productos (cashier, supervisor, admin, owner, viewer). */
    protected static string $viewPermission = 'view_products';

    /** Crear/editar se controla por rol en canCreate/canEdit. */
    protected static ?string $createPermission = null;

    protected static ?string $editPermission = null;

    protected static ?string $deletePermission = null;

    protected static ?string $model = ProductCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Categorías';

    protected static ?string $modelLabel = 'Categoría';

    protected static ?string $pluralModelLabel = 'Categorías';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 2;

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre de la Categoría')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if (filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        Forms\Components\TextInput::make('slug')
                            ->label('Slug')
                            ->maxLength(255)
                            ->helperText('Se genera solo si lo dejas vacío')
                            ->dehydrateStateUsing(fn (?string $state, Forms\Get $get) => filled($state)
                                ? $state
                                : Str::slug((string) $get('name'))),

                        Forms\Components\Select::make('parent_id')
                            ->label('Categoría padre (subcategoría)')
                            ->relationship(
                                name: 'parent',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->roots()->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Opcional: elige una padre para crear subcategoría (ej: Bebidas › Gaseosas)'),

                        Forms\Components\ColorPicker::make('color')
                            ->label('Color')
                            ->nullable(),

                        Forms\Components\TextInput::make('sort_order')
                            ->label('Orden')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Activa')
                            ->default(true),

                        Forms\Components\Textarea::make('description')
                            ->label('Descripción')
                            ->rows(3)
                            ->columnSpanFull(),
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
                    ->weight('bold')
                    ->description(fn (ProductCategory $r) => $r->parent
                        ? 'Subcategoría de '.$r->parent->name
                        : 'Categoría principal'),

                Tables\Columns\ColorColumn::make('color')
                    ->label('Color')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Padre')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('products_count')
                    ->label('Productos')
                    ->counts('products')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Solo activas')
                    ->falseLabel('Solo inactivas'),
                Tables\Filters\Filter::make('roots')
                    ->label('Solo principales')
                    ->query(fn (Builder $q) => $q->roots())
                    ->toggle(),
                Tables\Filters\TrashedFilter::make(),
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
            ->emptyStateHeading('Sin categorías')
            ->emptyStateDescription('Crea categorías como Bebidas, Medicamentos, Herramientas, Materias Primas…');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductCategories::route('/'),
            'create' => Pages\CreateProductCategory::route('/create'),
            'edit' => Pages\EditProductCategory::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canViewAny(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->canPerform('view_categories') ?? false;
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
