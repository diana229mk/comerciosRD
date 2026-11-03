<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Enums\PaymentMethod;
use App\Filament\Resources\SaleResource;
use App\Models\Sale;
use App\Services\SaleService;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Throwable;

class CreateSale extends CreateRecord
{
    protected static string $resource = SaleResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    #[Locked]
    public string $checkoutKey = '';

    public function mount(): void
    {
        parent::mount();
        $this->checkoutKey = (string) Str::uuid();
    }

    protected function afterCreate(): void
    {
        $this->checkoutKey = (string) Str::uuid();
    }

    /**
     * Cobrar desde el header: modal pago → crea venta completada → ticket.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('cobrar')
                ->label('Cobrar')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->modalHeading('Cobrar venta')
                ->modalSubmitActionLabel('Confirmar e imprimir')
                ->modalWidth('md')
                ->form(function (): array {
                    $total = (float) ($this->data['total'] ?? 0);
                    $fake = new Sale([
                        'total' => $total,
                        'customer_id' => $this->data['customer_id'] ?? null,
                    ]);

                    return SaleResource::getCobrarFormSchema($fake);
                })
                ->action(function (array $data) {
                    $method = PaymentMethod::normalize((string) ($data['payment_method'] ?? 'efectivo'));
                    $total = (float) ($this->data['total'] ?? 0);
                    $tendered = (float) ($data['amount_tendered'] ?? 0);

                    if ($method === PaymentMethod::Efectivo && $tendered < $total) {
                        Notification::make()
                            ->title('Monto insuficiente')
                            ->body('Debe entregar al menos RD$ '.number_format($total, 2))
                            ->danger()
                            ->send();

                        return;
                    }

                    if ($method->isCredit() && empty($data['customer_id']) && empty($this->data['customer_id'])) {
                        Notification::make()
                            ->title('Cliente requerido')
                            ->body('Para fiado / crédito debe elegir un cliente')
                            ->danger()
                            ->send();

                        return;
                    }

                    // Inyectar datos de cobro en el formulario antes de crear
                    $this->data['payment_method'] = $method->value;
                    if (! empty($data['customer_id'])) {
                        $this->data['customer_id'] = $data['customer_id'];
                    }

                    $change = $method === PaymentMethod::Efectivo
                        ? max(0, round($tendered - $total, 2))
                        : 0.0;

                    $this->data['amount_paid'] = $method->isCredit()
                        ? 0
                        : ($method === PaymentMethod::Efectivo ? $tendered : $total);
                    $this->data['amount_tendered'] = $method === PaymentMethod::Efectivo ? $tendered : null;
                    $this->data['change_amount'] = $method === PaymentMethod::Efectivo ? $change : null;

                    try {
                        $this->create();
                    } catch (Throwable) {
                        return;
                    }

                    /** @var Sale|null $record */
                    $record = $this->record;
                    if (! $record) {
                        return;
                    }

                    if ($method === PaymentMethod::Efectivo && $change > 0) {
                        Notification::make()
                            ->title('Vuelto: RD$ '.number_format($change, 2))
                            ->success()
                            ->duration(10000)
                            ->send();
                    }

                    Notification::make()
                        ->title('Venta cobrada')
                        ->body('Total: RD$ '.number_format((float) $record->total, 2))
                        ->success()
                        ->send();

                    return redirect()->to(
                        SaleResource::getUrl('ticket', ['record' => $record])
                    );
                }),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->label('Guardar (cobrar con datos del formulario)'),
            $this->getCancelFormAction(),
        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant();
        $businessId = $tenant?->getKey() ?? session('current_business_id');

        if (! $businessId) {
            throw new \RuntimeException('No hay negocio activo.');
        }

        $items = collect($data['items'] ?? [])
            ->filter(fn ($row) => ! empty($row['product_id']))
            ->map(fn ($row) => [
                'product_id' => $row['product_id'],
                'quantity' => $row['quantity'] ?? 1,
                'unit_price' => $row['unit_price'] ?? null,
            ])
            ->values()
            ->all();

        if ($items === []) {
            throw new \InvalidArgumentException('Agrega al menos un producto a la venta.');
        }

        try {
            return app(SaleService::class)->create([
                'idempotency_key' => $this->checkoutKey,
                'business_id' => (string) $businessId,
                'user_id' => auth()->id(),
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'items' => $items,
                'discount' => $data['discount'] ?? 0,
                'tax_rate' => $data['tax_rate'] ?? 18,
                'payment_method' => $data['payment_method'] ?? PaymentMethod::Efectivo->value,
                'amount_paid' => $data['amount_paid'] ?? null,
                'amount_tendered' => $data['amount_tendered'] ?? null,
                'change_amount' => $data['change_amount'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (Throwable $e) {
            Notification::make()
                ->title('No se pudo registrar la venta')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);

            throw $e;
        }
    }

    protected function getRedirectUrl(): string
    {
        // Si cobramos con el botón header, redirigimos al ticket desde la action.
        // Guardado normal → vista de la venta.
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Venta registrada';
    }
}
