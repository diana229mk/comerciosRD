<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\GiftCardResource\Pages;
use App\Models\GiftCard;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class GiftCardResource extends Resource
{
    use ChecksBusinessModule;

    protected static ?string $model = GiftCard::class;
    protected static string $businessModule = 'gift_cards';

    protected static bool $retailOnly = true;
    protected static string $viewPermission = 'view_sales';
    protected static string $createPermission = 'edit_sales';
    protected static string $editPermission = 'edit_sales';
    protected static ?string $navigationIcon = 'heroicon-o-credit-card';
    protected static ?string $navigationLabel = 'Gift Cards';
    protected static ?string $modelLabel = 'Gift Card';
    protected static ?string $pluralModelLabel = 'Gift Cards';
    protected static ?string $navigationGroup = 'Tienda';
    protected static ?int $navigationSort = 35;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')->label('Código')->required()->maxLength(40),
            Forms\Components\TextInput::make('amount')->label('Monto inicial')->numeric()->required(),
            Forms\Components\TextInput::make('balance')->label('Saldo')->numeric()->required(),
            Forms\Components\Select::make('status')->label('Estado')->options([
                'active' => 'Activa',
                'used' => 'Usada',
                'expired' => 'Vencida',
                'cancelled' => 'Cancelada',
            ])->default('active')->required(),
            Forms\Components\DatePicker::make('expires_at')->label('Vence'),
            Forms\Components\Textarea::make('notes')->label('Notas')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable(),
                Tables\Columns\TextColumn::make('amount')->money('DOP'),
                Tables\Columns\TextColumn::make('balance')->money('DOP'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('expires_at')->date('d/m/Y'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGiftCards::route('/'),
            'create' => Pages\CreateGiftCard::route('/create'),
            'edit' => Pages\EditGiftCard::route('/{record}/edit'),
        ];
    }
}
