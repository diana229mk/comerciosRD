<?php

namespace App\Filament\Resources;

use App\Enums\EcfType;
use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\EcfSequenceResource\Pages;
use App\Models\EcfSequence;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EcfSequenceResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'electronic_invoicing';

    protected static ?string $model = EcfSequence::class;

    protected static ?string $navigationIcon = 'heroicon-o-hashtag';

    protected static ?string $navigationLabel = 'Secuencias Fiscales';

    protected static ?string $modelLabel = 'Secuencia Fiscal';

    protected static ?string $pluralModelLabel = 'Secuencias Fiscales';

    protected static ?string $navigationGroup = 'Facturación Electrónica';

    protected static ?int $navigationSort = 1;

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Rango autorizado por la DGII')
                    ->schema([
                        Forms\Components\Select::make('ecf_type')
                            ->label('Tipo de Comprobante')
                            ->options(EcfType::options())
                            ->required()
                            ->native(false),

                        Forms\Components\TextInput::make('series')
                            ->label('Serie')
                            ->maxLength(10)
                            ->placeholder('Opcional'),

                        Forms\Components\TextInput::make('from_number')
                            ->label('Desde (número inicial)')
                            ->numeric()
                            ->required()
                            ->minValue(1),

                        Forms\Components\TextInput::make('to_number')
                            ->label('Hasta (número final)')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->gt('from_number'),

                        Forms\Components\TextInput::make('current_number')
                            ->label('Último número usado')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Dejar en 0 si es un rango nuevo'),

                        Forms\Components\DatePicker::make('valid_from')
                            ->label('Válido desde')
                            ->native(false),

                        Forms\Components\DatePicker::make('valid_until')
                            ->label('Válido hasta')
                            ->native(false),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Activa')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ecf_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state): string => $state instanceof EcfType
                        ? $state->value.' - '.$state->label()
                        : (string) $state)
                    ->badge()
                    ->searchable(),

                Tables\Columns\TextColumn::make('from_number')
                    ->label('Desde')
                    ->numeric(),

                Tables\Columns\TextColumn::make('to_number')
                    ->label('Hasta')
                    ->numeric(),

                Tables\Columns\TextColumn::make('current_number')
                    ->label('Último usado')
                    ->numeric(),

                Tables\Columns\TextColumn::make('remaining')
                    ->label('Disponibles')
                    ->state(fn (EcfSequence $record): int => $record->remainingNumbers())
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        (int) $state < 50 => 'danger',
                        (int) $state < 200 => 'warning',
                        default => 'success',
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),

                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->placeholder('—'),
            ])
            ->defaultSort('ecf_type')
            ->filters([
                Tables\Filters\SelectFilter::make('ecf_type')
                    ->options(EcfType::options()),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Activas'),
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
            ->emptyStateHeading('Sin secuencias fiscales')
            ->emptyStateDescription('Crea un rango autorizado por la DGII (ej. tipo 32 desde 1 hasta 1000).');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEcfSequences::route('/'),
            'create' => Pages\CreateEcfSequence::route('/create'),
            'edit' => Pages\EditEcfSequence::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        if (! \App\Support\EcfFeature::enabled()) {
            return false;
        }

        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }
}
