<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\JournalEntryResource\Pages;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\JournalEntryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class JournalEntryResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'accounting';

    protected static string $viewPermission = 'view_journal';

    protected static string $createPermission = 'create_journal';

    protected static string $editPermission = 'edit_journal';

    protected static ?string $deletePermission = null;

    protected static ?string $model = JournalEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Asientos Contables';

    protected static ?string $modelLabel = 'Asiento Contable';

    protected static ?string $pluralModelLabel = 'Asientos Contables';

    protected static ?string $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'entry_number';

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información del Asiento')
                    ->schema([
                        Forms\Components\TextInput::make('entry_number')
                            ->label('Número')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Se genera al guardar')
                            ->helperText('Automático: ASI-AAAA-00001'),

                        Forms\Components\DatePicker::make('date')
                            ->label('Fecha')
                            ->required()
                            ->default(now())
                            ->native(false)
                            ->disabled(fn (?JournalEntry $record): bool => $record?->isPosted() ?? false),

                        Forms\Components\TextInput::make('reference')
                            ->label('Referencia')
                            ->maxLength(100)
                            ->placeholder('Ej: Venta #VTA-2026-00015')
                            ->disabled(fn (?JournalEntry $record): bool => $record?->isPosted() ?? false),

                        Forms\Components\Select::make('status')
                            ->label('Estado')
                            ->options([
                                'borrador' => 'Borrador',
                                'publicado' => 'Publicado',
                            ])
                            ->default('borrador')
                            ->required()
                            ->native(false)
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Usa el botón “Publicar” cuando el asiento esté cuadrado.'),

                        Forms\Components\Textarea::make('description')
                            ->label('Descripción / Concepto')
                            ->required()
                            ->rows(2)
                            ->columnSpanFull()
                            ->disabled(fn (?JournalEntry $record): bool => $record?->isPosted() ?? false),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Líneas del Asiento')
                    ->description('La suma de débitos debe ser igual a la suma de créditos.')
                    ->schema([
                        Forms\Components\Repeater::make('lines')
                            ->relationship()
                            ->schema([
                                Forms\Components\Select::make('account_id')
                                    ->label('Cuenta')
                                    ->options(fn () => Account::query()
                                        ->where('is_active', true)
                                        ->orderBy('code')
                                        ->get()
                                        ->mapWithKeys(fn (Account $account) => [
                                            $account->id => $account->code.' - '.$account->name,
                                        ]))
                                    ->searchable()
                                    ->required()
                                    ->columnSpan(4),

                                Forms\Components\TextInput::make('debit')
                                    ->label('Débito')
                                    ->numeric()
                                    ->prefix('RD$')
                                    ->default(0)
                                    ->minValue(0)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                        if ((float) $state > 0) {
                                            $set('credit', 0);
                                        }
                                    })
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('credit')
                                    ->label('Crédito')
                                    ->numeric()
                                    ->prefix('RD$')
                                    ->default(0)
                                    ->minValue(0)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                        if ((float) $state > 0) {
                                            $set('debit', 0);
                                        }
                                    })
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('description')
                                    ->label('Descripción')
                                    ->columnSpan(4),
                            ])
                            ->columns(12)
                            ->defaultItems(2)
                            ->minItems(2)
                            ->addActionLabel('Agregar Línea')
                            ->reorderable(false)
                            ->columnSpanFull()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::calculateTotals($get, $set))
                            ->disabled(fn (?JournalEntry $record): bool => $record?->isPosted() ?? false),
                    ]),

                Forms\Components\Section::make('Totales')
                    ->schema([
                        Forms\Components\TextInput::make('total_debit')
                            ->label('Total Débito')
                            ->numeric()
                            ->prefix('RD$')
                            ->disabled()
                            ->dehydrated()
                            ->default(0),

                        Forms\Components\TextInput::make('total_credit')
                            ->label('Total Crédito')
                            ->numeric()
                            ->prefix('RD$')
                            ->disabled()
                            ->dehydrated()
                            ->default(0),

                        Forms\Components\Placeholder::make('balance_status')
                            ->label('Estado del Asiento')
                            ->content(function (Get $get): HtmlString {
                                $debit = (float) ($get('total_debit') ?? 0);
                                $credit = (float) ($get('total_credit') ?? 0);
                                // Si totales están en 0, sumar desde líneas del form
                                if ($debit == 0.0 && $credit == 0.0) {
                                    $lines = $get('lines') ?? [];
                                    if (is_array($lines)) {
                                        foreach ($lines as $line) {
                                            if (! is_array($line)) {
                                                continue;
                                            }
                                            $debit += (float) ($line['debit'] ?? 0);
                                            $credit += (float) ($line['credit'] ?? 0);
                                        }
                                    }
                                }
                                $diff = abs($debit - $credit);

                                if ($diff < 0.01 && ($debit > 0 || $credit > 0)) {
                                    return new HtmlString(
                                        '<span class="text-success-600 font-bold">✓ Asiento Cuadrado</span>'
                                    );
                                }

                                if ($debit == 0.0 && $credit == 0.0) {
                                    return new HtmlString(
                                        '<span class="text-gray-500">Agrega débitos y créditos</span>'
                                    );
                                }

                                return new HtmlString(
                                    '<span class="text-danger-600 font-bold">✗ Diferencia: RD$ '.number_format($diff, 2).'</span>'
                                );
                            }),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('entry_number')
                    ->label('Número')
                    ->searchable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Concepto')
                    ->limit(40)
                    ->searchable(),

                Tables\Columns\TextColumn::make('total_debit')
                    ->label('Débito')
                    ->money('DOP'),

                Tables\Columns\TextColumn::make('total_credit')
                    ->label('Crédito')
                    ->money('DOP'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->colors([
                        'gray' => 'borrador',
                        'success' => 'publicado',
                    ])
                    ->formatStateUsing(fn (string $state): string => $state === 'publicado' ? 'Publicado' : 'Borrador'),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Creado por')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'borrador' => 'Borrador',
                        'publicado' => 'Publicado',
                    ]),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('publicar')
                    ->label('Publicar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Publicar asiento')
                    ->modalDescription('Los débitos y créditos deben ser iguales. Luego no se podrá editar.')
                    ->visible(fn (JournalEntry $record): bool => $record->isDraft()
                        && static::canEdit($record))
                    ->action(function (JournalEntry $record): void {
                        try {
                            $record->recalculateTotals();
                            $record->refresh();

                            if (! $record->isBalanced()) {
                                Notification::make()
                                    ->title('El asiento no está cuadrado')
                                    ->body('Los débitos y créditos deben ser iguales. Débito: RD$ '
                                        .number_format((float) $record->total_debit, 2)
                                        .' · Crédito: RD$ '
                                        .number_format((float) $record->total_credit, 2))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            app(JournalEntryService::class)->publish($record);

                            Notification::make()
                                ->title('Asiento publicado correctamente')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('No se pudo publicar')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\EditAction::make()
                    ->visible(fn (JournalEntry $record): bool => $record->isDraft()),

                Tables\Actions\ViewAction::make(),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (JournalEntry $record): bool => $record->isDraft()),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Sin asientos contables')
            ->emptyStateDescription('Crea un asiento en borrador con al menos 2 líneas (débito y crédito).');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJournalEntries::route('/'),
            'create' => Pages\CreateJournalEntry::route('/create'),
            'edit' => Pages\EditJournalEntry::route('/{record}/edit'),
            'view' => Pages\ViewJournalEntry::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['creator']);
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = filament()->getTenant();

        return $tenant ? $tenant->hasModule('accounting') && static::canViewAny() : false;
    }

    public static function canViewAny(): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('accounting')) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('accounting')) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }

    public static function canEdit($record): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('accounting')) {
            return false;
        }

        // Solo se pueden editar asientos en borrador
        $isDraft = $record instanceof JournalEntry
            ? $record->isDraft()
            : (is_object($record) && ($record->status ?? null) === JournalEntry::STATUS_DRAFT);

        if (! $isDraft) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }

    public static function canDelete($record): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! $tenant->hasModule('accounting')) {
            return false;
        }

        // Solo se pueden eliminar asientos en borrador
        $isDraft = $record instanceof JournalEntry
            ? $record->isDraft()
            : (is_object($record) && ($record->status ?? null) === JournalEntry::STATUS_DRAFT);

        if (! $isDraft) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }

    // ==================== CÁLCULOS ====================

    public static function calculateTotals(Get|callable $get, Set|callable $set): void
    {
        $lines = $get('lines') ?? [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $totalDebit += (float) ($line['debit'] ?? 0);
                $totalCredit += (float) ($line['credit'] ?? 0);
            }
        }

        $set('total_debit', number_format($totalDebit, 2, '.', ''));
        $set('total_credit', number_format($totalCredit, 2, '.', ''));
    }
}
