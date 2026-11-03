<?php

namespace App\Filament\Resources\PrescriptionResource\Pages;

use App\Filament\Resources\PrescriptionResource;
use App\Models\Prescription;
use App\Services\SaleService;
use Filament\Actions;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class ViewPrescription extends ViewRecord
{
    protected static string $resource = PrescriptionResource::class;

    #[Locked]
    public string $dispenseCheckoutKey = '';

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->dispenseCheckoutKey = (string) Str::uuid();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn () => ! in_array($this->getRecord()->status, [
                    Prescription::STATUS_DISPENSED,
                    Prescription::STATUS_VOID,
                ], true)),

            Actions\Action::make('dispense')
                ->label('Dispensar')
                ->icon('heroicon-o-shopping-cart')
                ->color('success')
                ->visible(fn () => $this->getRecord()->isDispensable())
                ->form(function () {
                    $rx = $this->getRecord()->load('items.product');
                    $rows = [];
                    foreach ($rx->items as $it) {
                        $remaining = $it->remainingQuantity();
                        if ($remaining <= 0) {
                            continue;
                        }
                        $rows[] = Group::make([
                            Placeholder::make('info_'.$it->id)
                                ->label($it->product_name)
                                ->content("Recetado: {$it->quantity_prescribed} · Ya dispensado: {$it->quantity_dispensed} · Restante: {$remaining}"),
                            TextInput::make('qty_'.$it->id)
                                ->label('Cantidad a dispensar ahora')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue($remaining)
                                ->step(0.001)
                                ->default(0),
                            TextInput::make('price_'.$it->id)
                                ->label('Precio unitario (RD$)')
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01)
                                ->default(fn () => (float) ($it->product?->price ?? 0))
                                ->visible(fn () => (bool) $it->product_id),
                        ])->columns(3);
                    }

                    return array_merge($rows, [
                        Select::make('payment_method')
                            ->label('Método de pago')
                            ->options(fn (): array => array_filter([
                                'efectivo' => 'Efectivo',
                                'tarjeta' => 'Tarjeta',
                                'transferencia' => 'Transferencia',
                                'fiado' => 'Fiado / Crédito',
                            ], fn ($key): bool => $key !== 'fiado'
                                || (bool) $this->getRecord()->business?->hasModule('receivables'), ARRAY_FILTER_USE_KEY))
                            ->default('efectivo')
                            ->required()
                            ->native(false),

                        TextInput::make('tax_rate')
                            ->label('ITBIS %')
                            ->numeric()
                            ->default(18)
                            ->minValue(0),
                    ]);
                })
                ->action(function (array $data): void {
                    $rx = $this->getRecord()->load('items');
                    $items = [];
                    foreach ($rx->items as $it) {
                        $qty = (float) ($data['qty_'.$it->id] ?? 0);
                        if ($qty <= 0) {
                            continue;
                        }
                        if (! $it->product_id) {
                            Notification::make()
                                ->title('Producto no catalogado')
                                ->body("El renglón \"{$it->product_name}\" no tiene producto asociado; asócialo antes de dispensar.")
                                ->danger()
                                ->send();

                            return;
                        }
                        $items[] = [
                            'product_id' => (string) $it->product_id,
                            'prescription_item_id' => (string) $it->id,
                            'quantity' => $qty,
                            'unit_price' => (float) ($data['price_'.$it->id] ?? 0),
                        ];
                    }

                    if ($items === []) {
                        Notification::make()
                            ->title('Nada por dispensar')
                            ->body('Ingresa una cantidad mayor a cero en al menos un renglón.')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        $sale = app(SaleService::class)->create([
                            'idempotency_key' => $this->dispenseCheckoutKey,
                            'business_id' => (string) $rx->business_id,
                            'customer_id' => $rx->customer_id,
                            'customer_name' => $rx->patient_name,
                            'items' => $items,
                            'tax_rate' => (float) ($data['tax_rate'] ?? 18),
                            'payment_method' => (string) ($data['payment_method'] ?? 'efectivo'),
                            'notes' => 'Dispensación receta '.$rx->code,
                        ]);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('No se pudo dispensar')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->dispenseCheckoutKey = (string) Str::uuid();
                    Notification::make()
                        ->title('Dispensación registrada')
                        ->body('Venta '.$sale->sale_number.' — Total RD$ '.number_format((float) $sale->total, 2))
                        ->success()
                        ->send();

                    $this->redirect(static::getResource()::getUrl('view', ['record' => $rx]));
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->record($this->getRecord())
            ->schema([
                Section::make('Receta')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('code')->label('Código')->weight('bold'),
                            TextEntry::make('status')
                                ->label('Estado')
                                ->badge()
                                ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state))
                                ->color(fn (?string $state): string => match ($state) {
                                    Prescription::STATUS_DISPENSED => 'success',
                                    Prescription::STATUS_PARTIAL => 'warning',
                                    Prescription::STATUS_PENDING => 'info',
                                    default => 'danger',
                                }),
                            TextEntry::make('issued_at')->label('Emitida')->date('d/m/Y'),
                            TextEntry::make('valid_until')->label('Vigente hasta')->date('d/m/Y')->placeholder('—'),
                            TextEntry::make('diagnosis')->label('Diagnóstico')->placeholder('—')->columnSpan(2),
                        ]),
                    ]),

                Section::make('Paciente')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('patient_name')->label('Nombre'),
                            TextEntry::make('patient_document')->label('Documento')->placeholder('—'),
                            TextEntry::make('patient_age')->label('Edad')->placeholder('—'),
                        ]),
                    ]),

                Section::make('Médico')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('doctor_name')->label('Nombre'),
                            TextEntry::make('doctor_exequatur')->label('Exequátur')->placeholder('—'),
                            TextEntry::make('doctor_license')->label('Licencia')->placeholder('—'),
                            TextEntry::make('doctor_specialty')->label('Especialidad')->placeholder('—'),
                        ]),
                    ]),

                Section::make('Medicamentos')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('product_name')->label('Medicamento'),
                                TextEntry::make('quantity_prescribed')->label('Recetado')->numeric(3),
                                TextEntry::make('quantity_dispensed')->label('Dispensado')->numeric(3),
                                TextEntry::make('dosage')->label('Dosis')->placeholder('—'),
                                TextEntry::make('duration')->label('Duración')->placeholder('—'),
                                TextEntry::make('instructions')->label('Indicaciones')->placeholder('—')->columnSpanFull(),
                            ])
                            ->columns(3),
                    ]),

                Section::make('Notas')
                    ->schema([
                        TextEntry::make('notes')->label('')->placeholder('Sin notas'),
                    ])
                    ->collapsed()
                    ->visible(fn () => filled($this->getRecord()->notes)),
            ]);
    }
}
