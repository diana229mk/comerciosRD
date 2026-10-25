<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BranchResource\Pages;
use App\Models\Branch;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * CRUD de sucursales por negocio (FASE S1).
 *
 * No se conecta aún con POS/inventario/ventas: sólo administra la lista y
 * la asignación por usuario en el pivot user_business.
 */
class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Sucursales';

    protected static ?string $modelLabel = 'Sucursal';

    protected static ?string $pluralModelLabel = 'Sucursales';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'sucursales';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos de la sucursal')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(120)
                            ->placeholder('Sucursal Principal, Sucursal Villa Mella…'),

                        Forms\Components\TextInput::make('code')
                            ->label('Código')
                            ->maxLength(20)
                            ->placeholder('Opcional: SUC-01')
                            ->helperText('Corto y único dentro del negocio.'),

                        Forms\Components\TextInput::make('phone')
                            ->label('Teléfono')
                            ->tel()
                            ->maxLength(30),

                        Forms\Components\TextInput::make('address')
                            ->label('Dirección')
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notas')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Estado')
                    ->schema([
                        Forms\Components\Toggle::make('is_main')
                            ->label('Sucursal principal')
                            ->disabled()
                            ->helperText('La define ComerciosRD. No se cambia desde el local.'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Activa')
                            ->disabled()
                            ->helperText('Solo el panel Master activa o suspende una sucursal.'),
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

                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Teléfono')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('address')
                    ->label('Dirección')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_main')
                    ->label('Principal')
                    ->boolean(),

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
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->emptyStateHeading('Sin sucursales extra')
            ->emptyStateDescription('Una sucursal nueva la activa ComerciosRD (panel Master) y asigna el administrador de ese local.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBranches::route('/'),
            'create' => Pages\CreateBranch::route('/create'),
            'edit' => Pages\EditBranch::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRoleInBusiness(['owner', 'admin', 'supervisor']) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }
}
