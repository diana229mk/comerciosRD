<?php

namespace App\Filament\Pages;

use App\Imports\RetailCollectionImport as RetailCollectionImportImporter;
use App\Models\Business;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Maatwebsite\Excel\Facades\Excel;

class RetailCollectionImport extends Page implements \Filament\Forms\Contracts\HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $navigationLabel = 'Colección boutique';
    protected static ?string $title = 'Importar colección boutique';
    protected static ?string $navigationGroup = 'Tienda';
    protected static ?int $navigationSort = 75;
    protected static string $view = 'filament.pages.retail-collection-import';

    public ?array $data = [];

    public ?string $result = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\FileUpload::make('file')
                ->label('Archivo CSV / Excel de la colección')
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
        ])->statePath('data');
    }

    public function importCollection(): void
    {
        $state = $this->form->getState();
        $file = $state['file'] ?? null;
        if (! $file) {
            Notification::make()->title('Archivo requerido')->body('Sube el CSV o Excel de la colección.')->warning()->send();
            return;
        }

        $path = null;
        try {
            $path = app(\App\Services\PrivateDocumentStorage::class)
                ->materializeForProcessing((string) $file, 'imports');
            $importer = new RetailCollectionImportImporter();
            Excel::import($importer, $path);
            $this->result = "Colección: {$importer->created} creados, {$importer->updated} actualizados, {$importer->variants} variantes, {$importer->skipped} omitidos.";
            Notification::make()->title('Colección importada')->body($this->result)->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error en la importación')->body($e->getMessage())->danger()->send();
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
        if (! $tenant instanceof Business || ! $user instanceof User) {
            return false;
        }

        return $tenant->isRetailStore() && $user->hasRoleInBusiness(['owner', 'admin', 'manager']);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }
}
