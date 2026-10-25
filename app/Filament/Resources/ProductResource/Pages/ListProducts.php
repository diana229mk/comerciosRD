<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Exports\ProductsExport;
use App\Filament\Resources\ProductResource;
use App\Imports\ProductsImport;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nuevo Producto'),

            Actions\Action::make('export')
                ->label('Exportar Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function () {
                    $fileName = 'productos-'.now()->format('Y-m-d').'.xlsx';

                    return Excel::download(new ProductsExport, $fileName);
                }),

            Actions\Action::make('import')
                ->label('Importar Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('warning')
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label('Archivo Excel')
                        ->disk('private')
                        ->directory(fn (): string => app(\App\Services\PrivateDocumentStorage::class)
                            ->directoryForCurrentBusiness('imports'))
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                            'text/plain',
                        ])
                        ->maxSize(10240)
                        ->required()
                        ->helperText('Usa el mismo formato del archivo exportado. La primera fila debe ser el encabezado.'),
                ])
                ->action(function (array $data): void {
                    $relative = $data['file'] ?? null;
                    if (! is_string($relative) || $relative === '') {
                        Notification::make()
                            ->title('No se recibió el archivo')
                            ->danger()
                            ->send();

                        return;
                    }

                    $filePath = app(\App\Services\PrivateDocumentStorage::class)
                        ->materializeForProcessing($relative, 'imports');
                    try {
                        $import = new ProductsImport;
                        Excel::import($import, $filePath);

                        Notification::make()
                            ->title('Importación completada')
                            ->body(
                                "Creados: {$import->created} · Actualizados: {$import->updated}"
                                .($import->skipped > 0 ? " · Omitidos: {$import->skipped}" : '')
                            )
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Error al importar')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    } finally {
                        if ($filePath && is_file($filePath)) {
                            unlink($filePath);
                        }
                    }
                }),

            Actions\Action::make('download_template')
                ->label('Descargar Plantilla')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    // Misma estructura que la exportación (encabezados + ejemplos del tenant si hay)
                    $fileName = 'plantilla-productos.xlsx';

                    return Excel::download(new ProductsExport, $fileName);
                }),
        ];
    }
}
