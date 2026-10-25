<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\PrescriptionResource\Pages;
use App\Models\Customer;
use App\Models\Prescription;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PrescriptionResource extends Resource
{
    use ChecksBusinessModule;

    protected static ?string $model = Prescription::class;

    protected static string $businessModule = 'prescriptions';

    protected static bool $pharmacyOnly = true;

    protected static string $viewPermission = 'view_sales';

    protected static string $createPermission = 'create_sales';

    protected static string $editPermission = 'edit_sales';

    protected static ?string $deletePermission = null;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Recetas Médicas';

    protected static ?string $modelLabel = 'Receta';

    protected static ?string $pluralModelLabel = 'Recetas';

    protected static ?string $navigationGroup = 'Farmacia';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos del paciente')
                    ->schema([
                        Forms\Components\Select::make('customer_id')
                            ->label('Cliente registrado (opcional)')
                            ->options(fn () => Customer::query()->orderBy('name')->limit(200)->pluck('name', 'id'))
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                if (! $state) {
                                    return;
                                }
                                $c = Customer::query()->find($state);
                                if ($c) {
                                    $set('patient_name', $c->name);
                                    $set('patient_document', $c->document ?? $c->rnc ?? null);
                                }
                            }),

                        Forms\Components\TextInput::make('patient_name')
                            ->label('Nombre del paciente')
                            ->required()
                            ->maxLength(120),

                        Forms\Components\TextInput::make('patient_document')
                            ->label('Cédula / documento')
                            ->maxLength(30),

                        Forms\Components\TextInput::make('patient_age')
                            ->label('Edad')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(150),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Datos del médico')
                    ->schema([
                        Forms\Components\TextInput::make('doctor_name')
                            ->label('Nombre del médico')
                            ->required()
                            ->maxLength(120),

                        Forms\Components\TextInput::make('doctor_exequatur')
                            ->label('Exequátur')
                            ->maxLength(60),

                        Forms\Components\TextInput::make('doctor_license')
                            ->label('Licencia / colegiatura')
                            ->maxLength(60),

                        Forms\Components\TextInput::make('doctor_specialty')
                            ->label('Especialidad')
                            ->maxLength(80),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Vigencia y observaciones')
                    ->schema([
                        Forms\Components\DatePicker::make('issued_at')
                            ->label('Fecha de emisión')
                            ->required()
                            ->default(now())
                            ->native(false),

                        Forms\Components\DatePicker::make('valid_until')
                            ->label('Vigencia hasta (opcional)')
                            ->native(false),

                        Forms\Components\Textarea::make('diagnosis')
                            ->label('Diagnóstico')
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notas')
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\FileUpload::make('image_path')
                            ->label('Foto/escaneo de la receta')
                            ->image()
                            ->disk('private')
                            ->directory(fn (): string => app(\App\Services\PrivateDocumentStorage::class)
                                ->directoryForCurrentBusiness('prescriptions'))
                            ->visibility('private')
                            ->previewable(false)
                            ->downloadable(false)
                            ->openable(false)
                            ->maxSize(4096)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Medicamentos recetados')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship()
                            ->label('')
                            ->schema([
                                Forms\Components\Select::make('product_id')
                                    ->label('Producto')
                                    ->options(fn () => Product::query()
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->limit(500)
                                        ->pluck('name', 'id'))
                                    ->searchable()
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        if ($state) {
                                            $p = Product::query()->find($state);
                                            if ($p) {
                                                $set('product_name', $p->name);
                                            }
                                        }
                                    }),

                                Forms\Components\TextInput::make('product_name')
                                    ->label('Nombre / descripción')
                                    ->required()
                                    ->maxLength(120),

                                Forms\Components\TextInput::make('quantity_prescribed')
                                    ->label('Cantidad recetada')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.001)
                                    ->step(0.001),

                                Forms\Components\TextInput::make('dosage')
                                    ->label('Dosis')
                                    ->placeholder('ej. 500 mg cada 8 h')
                                    ->maxLength(120),

                                Forms\Components\TextInput::make('duration')
                                    ->label('Duración')
                                    ->placeholder('ej. 7 días')
                                    ->maxLength(60),

                                Forms\Components\Textarea::make('instructions')
                                    ->label('Indicaciones')
                                    ->rows(2)
                                    ->columnSpanFull(),
                            ])
                            ->columns(3)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Agregar medicamento')
                            ->reorderable(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('patient_name')
                    ->label('Paciente')
                    ->searchable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('doctor_name')
                    ->label('Médico')
                    ->searchable()
                    ->limit(30),

                Tables\Columns\TextColumn::make('issued_at')
                    ->label('Emitida')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Vigente hasta')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->color(fn ($record) => $record?->isExpired() ? 'danger' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        Prescription::STATUS_DISPENSED => 'success',
                        Prescription::STATUS_PARTIAL => 'warning',
                        Prescription::STATUS_PENDING => 'info',
                        Prescription::STATUS_EXPIRED, Prescription::STATUS_VOID => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Renglones')
                    ->counts('items')
                    ->toggleable(),
            ])
            ->defaultSort('issued_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        Prescription::STATUS_PENDING => 'Pendiente',
                        Prescription::STATUS_PARTIAL => 'Parcial',
                        Prescription::STATUS_DISPENSED => 'Dispensada',
                        Prescription::STATUS_EXPIRED => 'Vencida',
                        Prescription::STATUS_VOID => 'Anulada',
                    ]),

                Tables\Filters\Filter::make('issued_at')
                    ->form([
                        Forms\Components\DatePicker::make('desde')->label('Desde'),
                        Forms\Components\DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when($data['desde'] ?? null, fn ($q, $v) => $q->whereDate('issued_at', '>=', $v))
                            ->when($data['hasta'] ?? null, fn ($q, $v) => $q->whereDate('issued_at', '<=', $v));
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('download_prescription_image')
                    ->label('Descargar receta')
                    ->icon('heroicon-o-document-arrow-down')
                    ->url(fn (Prescription $record): ?string => filled($record->image_path) && filament()->getTenant()
                        ? route('private-documents.prescriptions.image', [
                            'tenant' => filament()->getTenant()->slug,
                            'prescription' => $record->getKey(),
                        ])
                        : null)
                    ->openUrlInNewTab()
                    ->visible(fn (Prescription $record): bool => filled($record->image_path)),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (Prescription $r) => ! in_array($r->status, [Prescription::STATUS_DISPENSED, Prescription::STATUS_VOID], true)),
                Tables\Actions\Action::make('void')
                    ->label('Anular')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Motivo')
                            ->rows(2)
                            ->required(),
                    ])
                    ->action(function (Prescription $record, array $data): void {
                        app(\App\Services\PrescriptionService::class)->void($record, $data['reason'] ?? null);
                    })
                    ->visible(fn (Prescription $r) => ! in_array($r->status, [Prescription::STATUS_VOID, Prescription::STATUS_DISPENSED], true)),
            ])
            ->emptyStateHeading('No hay recetas registradas')
            ->emptyStateDescription('Registra recetas médicas para dispensar medicamentos controlados.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPrescriptions::route('/'),
            'create' => Pages\CreatePrescription::route('/create'),
            'view' => Pages\ViewPrescription::route('/{record}'),
            'edit' => Pages\EditPrescription::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! method_exists($tenant, 'isPharmacy') || ! $tenant->isPharmacy()) {
            return false;
        }

        if (! static::tenantHasModule()) {
            return false;
        }

        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $tenant = filament()->getTenant();
        if (! $tenant || ! method_exists($tenant, 'isPharmacy') || ! $tenant->isPharmacy()) {
            return false;
        }

        return static::tenantHasModule() && (auth()->user()?->canPerform('view_sales') ?? false);
    }
}
