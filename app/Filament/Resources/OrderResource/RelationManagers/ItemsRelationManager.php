<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Models\Product;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Productos de la orden';

    protected static ?string $modelLabel = 'ítem';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->label('Producto')
                    ->options(fn () => Product::query()
                        ->active()
                        ->orderBy('name')
                        ->limit(200)
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if (! $state) {
                            return;
                        }
                        $product = Product::query()->find($state);
                        if ($product) {
                            $set('product_name', $product->name);
                            $set('unit_price', $product->price);
                            $set('unit', $product->unit ?? 'unidad');
                        }
                    })
                    ->columnSpanFull(),

                Forms\Components\Hidden::make('product_name'),
                Forms\Components\Hidden::make('unit')->default('unidad'),

                Forms\Components\TextInput::make('unit_price')
                    ->label('Precio unitario')
                    ->numeric()
                    ->prefix('RD$')
                    ->required()
                    ->minValue(0)
                    ->live(onBlur: true),

                Forms\Components\TextInput::make('quantity')
                    ->label('Cantidad')
                    ->numeric()
                    ->required()
                    ->default(1)
                    ->minValue(0.001)
                    ->live(onBlur: true),

                Forms\Components\Placeholder::make('line_subtotal')
                    ->label('Subtotal línea')
                    ->content(function (Get $get): string {
                        $price = (float) ($get('unit_price') ?? 0);
                        $qty = (float) ($get('quantity') ?? 0);

                        return 'RD$ '.number_format($price * $qty, 2, '.', ',');
                    }),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_name')
            ->columns([
                Tables\Columns\TextColumn::make('product_name')
                    ->label('Producto')
                    ->searchable()
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('unit_price')
                    ->label('P. unit.')
                    ->money('DOP'),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Cant.')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('DOP')
                    ->weight('bold'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Agregar producto')
                    ->mutateFormDataUsing(function (array $data): array {
                        if (empty($data['product_name']) && ! empty($data['product_id'])) {
                            $data['product_name'] = Product::query()->find($data['product_id'])?->name
                                ?? 'Producto';
                        }

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Sin productos en la orden')
            ->emptyStateDescription('Agrega productos del inventario de este negocio.');
    }
}
