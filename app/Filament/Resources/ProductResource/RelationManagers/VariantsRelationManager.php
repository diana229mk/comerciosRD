<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Business;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class VariantsRelationManager extends RelationManager
{
    use Concerns\LoadsEagerly;

    protected static string $relationship = 'variants';
    protected static ?string $title = 'Variantes';
    protected static ?string $icon = 'heroicon-o-swatch';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        $t = filament()->getTenant();
        return $t instanceof Business && $t->usesAdvancedInventory();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('sku')->required()->maxLength(60),
            Forms\Components\TextInput::make('barcode')->maxLength(60),
            Forms\Components\KeyValue::make('attributes')->label('Atributos (color, tamaño, etc.)')
                ->keyLabel('Atributo')->valueLabel('Valor'),
            Forms\Components\TextInput::make('price')->numeric()->required(),
            Forms\Components\TextInput::make('cost')->numeric(),
            Forms\Components\TextInput::make('stock')->numeric()->default(0),
            Forms\Components\Toggle::make('is_active')->default(true),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('sku')->searchable(),
            Tables\Columns\TextColumn::make('barcode'),
            Tables\Columns\TextColumn::make('price')->money('DOP'),
            Tables\Columns\TextColumn::make('stock')->numeric(3),
            Tables\Columns\IconColumn::make('is_active')->boolean(),
        ])->headerActions([Tables\Actions\CreateAction::make()])
        ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
