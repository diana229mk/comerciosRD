<?php

namespace App\Filament\Pages;

use App\Filament\Resources\PurchaseResource;
use App\Models\Business;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Services\ReorderService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ReorderAlerts extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Reposición';

    protected static ?string $title = 'Alertas de Stock Mínimo y Sugerencia de Reposición';

    protected static ?string $navigationGroup = 'Inventario';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.reorder-alerts';

    public ?array $filters = [
        'category_id' => null,
        'supplier_id' => null,
        'window_days' => 30,
        'target_days' => 30,
        'controlled_only' => false,
    ];

    /** @var array<string, float> */
    public array $qty = [];

    /** @var array<string, bool> */
    public array $selected = [];

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return false;
        }
        $modules = $tenant->enabled_modules ?? [];
        $user = auth()->user();
        $role = $user?->getRoleInCurrentBusiness();

        return in_array('inventory_movements', $modules, true)
            && in_array($role, ['owner', 'admin', 'supervisor'], true);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public function mount(): void
    {
        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(5)->schema([
                Forms\Components\Select::make('category_id')
                    ->label('Categoría')
                    ->options(fn () => ProductCategory::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                Forms\Components\Select::make('supplier_id')
                    ->label('Proveedor')
                    ->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                Forms\Components\TextInput::make('window_days')
                    ->label('Ventana consumo (días)')
                    ->numeric()->minValue(1)->maxValue(365)->default(30),
                Forms\Components\TextInput::make('target_days')
                    ->label('Cobertura objetivo (días)')
                    ->numeric()->minValue(1)->maxValue(365)->default(30),
                Forms\Components\Toggle::make('controlled_only')
                    ->label('Solo controlados'),
            ]),
        ])->statePath('filters');
    }

    public function getSuggestions()
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return collect();
        }

        $suggestions = app(ReorderService::class)->suggestions($tenant->id, [
            'window_days' => (int) ($this->filters['window_days'] ?? 30),
            'target_days' => (int) ($this->filters['target_days'] ?? 30),
            'category_id' => $this->filters['category_id'] ?? null,
            'supplier_id' => $this->filters['supplier_id'] ?? null,
            'controlled_only' => (bool) ($this->filters['controlled_only'] ?? false),
        ]);

        foreach ($suggestions as $s) {
            $id = (string) $s['product']->id;
            if (! array_key_exists($id, $this->qty)) {
                $this->qty[$id] = (float) $s['suggested_qty'];
            }
            if (! array_key_exists($id, $this->selected)) {
                $this->selected[$id] = true;
            }
        }

        return $suggestions;
    }

    public function applyFilters(): void
    {
        $this->qty = [];
        $this->selected = [];
    }

    /**
     * @return list<array{product_id: string, quantity: float, unit_cost: float, supplier_id: ?string}>
     */
    protected function selectedLines(): array
    {
        $lines = [];
        foreach ($this->getSuggestions() as $s) {
            $id = (string) $s['product']->id;
            if (! ($this->selected[$id] ?? false)) {
                continue;
            }
            $q = (float) ($this->qty[$id] ?? $s['suggested_qty']);
            if ($q <= 0) {
                continue;
            }
            $lines[] = [
                'product_id' => $id,
                'quantity' => $q,
                'unit_cost' => (float) $s['product']->cost,
                'supplier_id' => $s['supplier']?->id,
            ];
        }

        return $lines;
    }

    protected function getHeaderActions(): array
    {
        $tenant = Filament::getTenant();
        $isPharmacy = $tenant instanceof Business && $tenant->isPharmacy();

        return [
            Action::make('receive_now')
                ->label('Ya lo compré — sumar al stock')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Sumar mercancía al inventario')
                ->modalDescription('El producto no se borra cuando se acaba. Esto registra la compra y sube el stock para que puedas venderlo otra vez.')
                ->form($isPharmacy ? [
                    Forms\Components\DatePicker::make('expires_at')
                        ->label('Vencimiento del lote')
                        ->required()
                        ->native(false)
                        ->default(now()->addYear()),
                    Forms\Components\TextInput::make('batch_code')
                        ->label('Código de lote (opcional)')
                        ->maxLength(60),
                ] : [])
                ->action(function (array $data) use ($tenant): void {
                    if (! $tenant instanceof Business) {
                        return;
                    }
                    $lines = $this->selectedLines();
                    if ($lines === []) {
                        Notification::make()->title('Marca al menos un producto')->warning()->send();

                        return;
                    }
                    try {
                        $orders = app(ReorderService::class)->restockNow(
                            (string) $tenant->id,
                            $lines,
                            auth()->id(),
                            [
                                'expires_at' => $data['expires_at'] ?? null,
                                'batch_code' => $data['batch_code'] ?? null,
                            ],
                        );
                        $this->qty = [];
                        $this->selected = [];
                        Notification::make()
                            ->title('Stock actualizado')
                            ->body('Ya puedes vender de nuevo. Compras: '.collect($orders)->pluck('purchase_number')->implode(', '))
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('No se pudo sumar el stock')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('generate_purchase')
                ->label('Generar orden de compra')
                ->icon('heroicon-o-shopping-cart')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Crea la orden en Borrador. El stock NO sube todavía: cuando llegue la mercancía, ábrela en Compras y pulsa “Marcar como Recibida”.')
                ->action(function () use ($tenant): void {
                    if (! $tenant instanceof Business) {
                        return;
                    }
                    $lines = $this->selectedLines();
                    if ($lines === []) {
                        Notification::make()->title('Nada seleccionado')->warning()->send();

                        return;
                    }
                    try {
                        $orders = app(ReorderService::class)->createPurchaseDraft((string) $tenant->id, $lines);
                        $first = $orders[0] ?? null;
                        $codes = collect($orders)->pluck('purchase_number')->implode(', ');
                        $n = Notification::make()
                            ->title('Orden creada (aún no suma stock)')
                            ->body($codes.'. Ábrela y márcala Recibida cuando tengas el producto.')
                            ->success();
                        if ($first) {
                            $n->actions([
                                NotificationAction::make('open')
                                    ->label('Abrir y recibir')
                                    ->url(PurchaseResource::getUrl('edit', ['record' => $first], panel: 'admin', tenant: $tenant)),
                            ]);
                        }
                        $n->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
