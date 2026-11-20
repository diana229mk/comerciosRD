<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use App\Models\ElectronicInvoice;
use App\Models\Sale;
use App\Models\SaleItemBatch;
use App\Models\SaleReturnOperation;
use App\Services\SaleReturnOperationService;
use App\Services\SaleService;
use App\Support\EcfFeature;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('imprimir_ticket')
                ->label('Imprimir ticket')
                ->icon('heroicon-o-printer')
                ->color('success')
                ->visible(fn (): bool => SaleResource::saleIsCompleted($this->getRecord()))
                ->url(fn (): string => SaleResource::getUrl('ticket', ['record' => $this->getRecord()]))
                ->openUrlInNewTab(),

            Actions\Action::make('pdf_termico')
                ->label('PDF 80mm')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => SaleResource::saleIsCompleted($this->getRecord()))
                ->url(fn (): string => route('print.ticket', $this->getRecord()))
                ->openUrlInNewTab(),

            Actions\Action::make('devolucion_parcial')
                ->label('Devolución al lote')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (): bool => $this->canReturnFromAllocations())
                ->modalHeading('Devolver mercancía al lote')
                ->modalDescription(new HtmlString(
                    'Indica cuánta mercancía devuelve el cliente <b>por cada lote despachado</b>.<br>'
                    .'La cantidad regresará al lote original y aumentará el stock.<br>'
                    .'<b>Reembolso:</b> efectivo requiere caja abierta; si no la hay o el pago fue electrónico, '
                    .'se crea un pendiente durable. Crédito reduce la CxC y conserva cualquier saldo a reembolsar.'
                ))
                ->form(fn () => $this->buildReturnForm())
                ->action(function (array $data): void {
                    $quantities = collect($data['allocations'] ?? [])
                        ->mapWithKeys(fn (array $row) => [
                            (string) $row['id'] => (float) ($row['return_qty'] ?? 0),
                        ])
                        ->filter(fn ($q) => $q > 0)
                        ->all();

                    if ($quantities === []) {
                        Notification::make()
                            ->title('Nada por devolver')
                            ->body('Ingresa al menos una cantidad mayor que cero.')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        $result = app(SaleService::class)
                            ->returnFromAllocations(
                                $this->getRecord(),
                                $quantities,
                                true,
                                isset($data['issue_credit_note']) ? (bool) $data['issue_credit_note'] : null,
                                isset($data['request_key']) ? (string) $data['request_key'] : null,
                            );
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('No se pudo procesar la devolución')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $lines = [
                        count($result['movements']).' movimiento(s) de reposición al lote.',
                        'Reembolso: RD$ '.number_format((float) $result['refund_amount'], 2),
                    ];
                    if (! empty($result['refund_note'])) {
                        $lines[] = $result['refund_note'];
                    }
                    if (! empty($result['credit_note'])) {
                        $lines[] = 'Nota de Crédito e-CF emitida: '.$result['credit_note']->ecf_number;
                    } elseif (! empty($result['credit_note_error'])) {
                        $lines[] = 'No se pudo emitir NC e-CF: '.$result['credit_note_error'];
                    } elseif ($result['credit_note_status'] === 'pending') {
                        $lines[] = 'La Nota de Crédito quedó pendiente de emisión/reintento.';
                    }
                    if ($result['refund_status'] === 'pending') {
                        $lines[] = 'El reembolso permanece pendiente de liquidación.';
                    }

                    $hasPending = $result['refund_status'] === 'pending'
                        || $result['credit_note_status'] === 'pending';
                    $color = $hasPending ? 'warning' : 'success';

                    $notif = Notification::make()
                        ->title('Devolución registrada')
                        ->body(implode("\n", $lines));

                    $color === 'warning' ? $notif->warning() : $notif->success();
                    $notif->send();

                    $this->refreshFormData(['items']);
                }),

            Actions\Action::make('liquidar_reembolso')
                ->label('Liquidar reembolso pendiente')
                ->icon('heroicon-o-banknotes')
                ->color('warning')
                ->visible(fn (): bool => app(SaleReturnOperationService::class)
                    ->pendingRefundsForSale($this->getRecord()) !== [])
                ->form([
                    Forms\Components\Select::make('operation_id')
                        ->label('Reembolso pendiente')
                        ->options(fn (): array => app(SaleReturnOperationService::class)
                            ->pendingRefundsForSale($this->getRecord()))
                        ->required()
                        ->native(false),
                    Forms\Components\Select::make('payment_method')
                        ->label('Método de liquidación')
                        ->options([
                            'efectivo' => 'Efectivo (requiere caja abierta)',
                            'transferencia' => 'Transferencia',
                            'tarjeta' => 'Tarjeta',
                            'cheque' => 'Cheque',
                        ])
                        ->required()
                        ->default('efectivo')
                        ->native(false),
                    Forms\Components\Hidden::make('request_key')
                        ->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(function (array $data): void {
                    try {
                        $operation = SaleReturnOperation::withoutBusinessScope()
                            ->where('business_id', $this->getRecord()->business_id)
                            ->where('sale_id', $this->getRecord()->id)
                            ->whereKey($data['operation_id'])
                            ->firstOrFail();
                        app(SaleReturnOperationService::class)->settleRefund(
                            $operation,
                            (string) $data['payment_method'],
                            (string) $data['request_key'],
                        );
                        Notification::make()
                            ->title('Reembolso liquidado')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('No se pudo liquidar el reembolso')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    protected function canReturnFromAllocations(): bool
    {
        /** @var Sale $sale */
        $sale = $this->getRecord();

        if (! app(SaleService::class)->canReturn($sale)) {
            return false;
        }

        if (! SaleResource::saleIsCompleted($sale)) {
            return false;
        }

        return SaleItemBatch::query()
            ->where('sale_id', $sale->id)
            ->whereColumn('returned_quantity', '<', 'quantity')
            ->exists();
    }

    /**
     * @return list<Component>
     */
    protected function buildReturnForm(): array
    {
        /** @var Sale $sale */
        $sale = $this->getRecord();

        $rows = SaleItemBatch::query()
            ->with(['batch', 'saleItem'])
            ->where('sale_id', $sale->id)
            ->whereColumn('returned_quantity', '<', 'quantity')
            ->get()
            ->map(fn (SaleItemBatch $a) => [
                'id' => (string) $a->id,
                'product' => $a->saleItem?->product_name ?? '—',
                'batch' => $a->batch?->code ?: 'sin código',
                'expires' => $a->batch?->expires_at?->format('d/m/Y') ?? '—',
                'sold' => number_format((float) $a->quantity, 3),
                'returned' => number_format((float) $a->returned_quantity, 3),
                'max' => (float) $a->returnableQuantity(),
                'return_qty' => 0,
            ])
            ->values()
            ->all();

        $schema = [
            Forms\Components\Hidden::make('request_key')
                ->default(fn (): string => (string) Str::uuid()),
            Forms\Components\Repeater::make('allocations')
                ->label('Lotes devolubles')
                ->default($rows)
                ->schema([
                    Forms\Components\Hidden::make('id'),
                    Forms\Components\TextInput::make('product')
                        ->label('Producto')
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpan(3),
                    Forms\Components\TextInput::make('batch')
                        ->label('Lote')
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpan(2),
                    Forms\Components\TextInput::make('expires')
                        ->label('Vence')
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpan(2),
                    Forms\Components\TextInput::make('sold')
                        ->label('Vendido')
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpan(1),
                    Forms\Components\TextInput::make('returned')
                        ->label('Ya devuelto')
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpan(1),
                    Forms\Components\Hidden::make('max'),
                    Forms\Components\TextInput::make('return_qty')
                        ->label('Devolver ahora')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.001')
                        ->default(0)
                        ->rules([
                            fn (Forms\Get $get) => function (string $attr, $value, \Closure $fail) use ($get) {
                                $max = (float) $get('max');
                                if ((float) $value > $max + 1e-6) {
                                    $fail("Máximo devolvible: {$max}");
                                }
                            },
                        ])
                        ->columnSpan(3),
                ])
                ->columns(12)
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->columnSpanFull(),
        ];

        if (EcfFeature::enabled()) {
            $schema[] = Forms\Components\Toggle::make('issue_credit_note')
                ->label('Emitir Nota de Crédito e-CF (tipo 34)')
                ->helperText('Genera una NC fiscal referenciando el e-CF original de esta venta.')
                ->default(function () use ($sale): bool {
                    $b = $sale->business;
                    if (! $b) {
                        return false;
                    }
                    $hasOriginal = ElectronicInvoice::withoutBusinessScope()
                        ->where('sale_id', $sale->id)
                        ->where('business_id', $b->id)
                        ->whereIn('ecf_type', ['31', '32'])
                        ->exists();

                    return (bool) ($b->settings['ecf']['auto_credit_note_on_return'] ?? $hasOriginal);
                })
                ->columnSpanFull();
        }

        return $schema;
    }
}
