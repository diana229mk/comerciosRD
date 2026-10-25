<?php

namespace App\Filament\Pages;

use App\Imports\ProductsImport;
use App\Imports\SupplierPriceListImport;
use App\Models\Business;
use App\Models\Supplier;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Maatwebsite\Excel\Facades\Excel;

class HardwareBulkImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Importación masiva';
    protected static ?string $title = 'Importación masiva Excel';
    protected static ?string $navigationGroup = 'Ferretería';
    protected static ?int $navigationSort = 70;
    protected static string $view = 'filament.pages.hardware-bulk-import';
    protected static ?string $slug = 'importacion-masiva';

    public ?array $productsData = [];
    public ?array $pricesData = [];
    public ?string $result = null;

    public function mount(): void
    {
        $this->productsForm->fill();
        $this->pricesForm->fill();
    }

    protected function getForms(): array
    {
        return ['productsForm', 'pricesForm'];
    }

    public function productsForm(Form $form): Form
    {
        return $form->schema([
            Forms\Components\FileUpload::make('file')
                ->label('Archivo Excel de productos (.xlsx / .xls / .csv)')
                ->acceptedFileTypes([
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-excel',
                    'text/csv',
                ])
                ->disk('private')
                ->directory(fn (): string => app(\App\Services\PrivateDocumentStorage::class)
                    ->directoryForCurrentBusiness('imports'))
                ->maxSize(10240)
                ->required(),
        ])->statePath('productsData');
    }

    public function pricesForm(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('supplier_id')
                ->label('Proveedor')
                ->options(fn () => Supplier::query()->pluck('name', 'id'))
                ->searchable()
                ->required(),
            Forms\Components\FileUpload::make('file')
                ->label('Archivo Excel de lista de precios (.xlsx / .xls / .csv)')
                ->acceptedFileTypes([
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-excel',
                    'text/csv',
                ])
                ->disk('private')
                ->directory(fn (): string => app(\App\Services\PrivateDocumentStorage::class)
                    ->directoryForCurrentBusiness('imports'))
                ->maxSize(10240)
                ->required(),
        ])->statePath('pricesData');
    }

    public function importProducts(): void
    {
        $data = $this->productsForm->getState();
        $path = null;
        try {
            $path = app(\App\Services\PrivateDocumentStorage::class)
                ->materializeForProcessing((string) ($data['file'] ?? ''), 'imports');
            $importer = new ProductsImport();
            Excel::import($importer, $path);
            $this->result = "Productos: {$importer->created} creados, {$importer->updated} actualizados, {$importer->skipped} omitidos.";
            Notification::make()->title('Importación completada')->body($this->result)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al importar')->body($e->getMessage())->danger()->send();
        } finally {
            if ($path && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function importPrices(): void
    {
        $data = $this->pricesForm->getState();
        $path = null;
        try {
            $path = app(\App\Services\PrivateDocumentStorage::class)
                ->materializeForProcessing((string) ($data['file'] ?? ''), 'imports');
            $importer = new SupplierPriceListImport($data['supplier_id']);
            Excel::import($importer, $path);
            $this->result = "Lista de precios: {$importer->matched} productos encontrados, {$importer->updated} actualizados, {$importer->notFound} no encontrados, {$importer->skipped} omitidos.";
            Notification::make()->title('Importación completada')->body($this->result)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al importar')->body($e->getMessage())->danger()->send();
        } finally {
            if ($path && is_file($path)) {
                unlink($path);
            }
        }
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = filament()->getTenant();
        $user = auth()->user();
        if (! $tenant instanceof Business || ! $user instanceof User) return false;
        if (! $tenant->isHardwareStore()) return false;
        return $user->hasRoleInBusiness(['owner', 'admin']);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }
}
