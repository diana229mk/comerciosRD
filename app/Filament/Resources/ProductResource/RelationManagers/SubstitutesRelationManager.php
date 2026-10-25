<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Business;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SubstitutesRelationManager extends RelationManager
{
    use Concerns\LoadsEagerly;

    protected static string $relationship = 'substitutes';
    protected static ?string $title = 'Sustitutos / Equivalentes';
    protected static ?string $icon = 'heroicon-o-arrow-path';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        $t = filament()->getTenant();
        return $t instanceof Business && $t->usesAdvancedInventory();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('substitute_id')->label('Sustituto')
                ->options(fn () => Product::query()->orderBy('name')->limit(500)->pluck('name', 'id'))
                ->searchable()->required(),
            Forms\Components\TextInput::make('priority')->label('Prioridad')->numeric()->default(10),
            Forms\Components\TextInput::make('reason')->label('Razón / nota'),
        ])->columns(3);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('substitute.name')->label('Producto'),
            Tables\Columns\TextColumn::make('substitute.sku'),
            Tables\Columns\TextColumn::make('substitute.stock')->label('Stock actual')->numeric(3),
            Tables\Columns\TextColumn::make('priority')->sortable(),
            Tables\Columns\TextColumn::make('reason'),
        ])->defaultSort('priority')
        ->headerActions([Tables\Actions\CreateAction::make()])
        ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
