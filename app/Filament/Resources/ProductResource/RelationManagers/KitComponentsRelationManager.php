<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Business;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class KitComponentsRelationManager extends RelationManager
{
    use Concerns\LoadsEagerly;

    protected static string $relationship = 'kitComponents';
    protected static ?string $title = 'Ingredientes / componentes';
    protected static ?string $icon = 'heroicon-o-cube';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        $t = filament()->getTenant();
        return $t instanceof Business
            && ($t->usesAdvancedInventory() || $t->usesKitchenRecipes())
            && ($ownerRecord->is_kit ?? false);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('component_product_id')->label('Componente')
                ->options(fn () => Product::query()->where('is_kit', false)->orderBy('name')->limit(500)->pluck('name', 'id'))
                ->searchable()->required(),
            Forms\Components\TextInput::make('quantity')->label('Cantidad')->numeric()->default(1)->required(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('component.name')->label('Producto'),
            Tables\Columns\TextColumn::make('component.sku'),
            Tables\Columns\TextColumn::make('quantity')->numeric(3),
        ])->headerActions([Tables\Actions\CreateAction::make()])
        ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
