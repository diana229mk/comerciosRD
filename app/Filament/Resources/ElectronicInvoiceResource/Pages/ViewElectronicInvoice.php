<?php

namespace App\Filament\Resources\ElectronicInvoiceResource\Pages;

use App\Filament\Resources\ElectronicInvoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewElectronicInvoice extends ViewRecord
{
    protected static string $resource = ElectronicInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        if (! \App\Support\EcfFeature::enabled()) {
            return [
                Actions\Action::make('ver_venta')
                    ->label('Ver venta')
                    ->icon('heroicon-o-shopping-cart')
                    ->url(fn (): ?string => $this->record->sale_id
                        ? \App\Filament\Resources\SaleResource::getUrl('view', ['record' => $this->record->sale_id])
                        : null)
                    ->visible(fn (): bool => filled($this->record->sale_id)),
            ];
        }

        return [
            Actions\Action::make('descargar_pdf')
                ->label('Descargar PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('danger')
                ->action(function () {
                    $record = $this->record;
                    $pdf = \App\Services\EcfService::generatePdf($record);

                    return response()->streamDownload(function () use ($pdf): void {
                        echo $pdf->output();
                    }, $record->ecf_number.'.pdf', [
                        'Content-Type' => 'application/pdf',
                    ]);
                }),

            Actions\Action::make('descargar_xml')
                ->label('Descargar XML')
                ->icon('heroicon-o-code-bracket')
                ->color('gray')
                ->visible(fn (): bool => filled($this->record->xml_content) || true)
                ->action(function () {
                    $record = $this->record;
                    if (empty($record->xml_content)) {
                        \App\Services\EcfService::regenerateXml($record);
                        $record->refresh();
                    }

                    return response()->streamDownload(function () use ($record): void {
                        echo $record->xml_content;
                    }, $record->ecf_number.'.xml', [
                        'Content-Type' => 'application/xml; charset=UTF-8',
                    ]);
                }),

            Actions\Action::make('ver_venta')
                ->label('Ver venta')
                ->icon('heroicon-o-shopping-cart')
                ->url(fn (): ?string => $this->record->sale_id
                    ? \App\Filament\Resources\SaleResource::getUrl('view', ['record' => $this->record->sale_id])
                    : null)
                ->visible(fn (): bool => filled($this->record->sale_id)),

            Actions\Action::make('enviar_dgii')
                ->label('Enviar a DGII')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Ambiente: '.config('services.dgii.environment', 'sandbox').'. Se firmará y enviará el XML al web service DGII.')
                ->action(function () {
                    try {
                        $result = app(\App\Services\DgiiSubmissionService::class)->submit($this->record);
                        if ($result['ok']) {
                            \Filament\Notifications\Notification::make()
                                ->title('e-CF enviado')
                                ->body('trackId: '.($result['track_id'] ?? '—'))
                                ->success()->send();
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->title('No se pudo enviar a DGII')
                                ->body($result['message'] ?? 'Error desconocido')
                                ->warning()->send();
                        }
                    } catch (\Throwable $e) {
                        \Filament\Notifications\Notification::make()
                            ->title('Error')->body($e->getMessage())->danger()->send();
                    }
                }),

            Actions\Action::make('consultar_estado_dgii')
                ->label('Consultar estado DGII')
                ->icon('heroicon-o-magnifying-glass')
                ->color('gray')
                ->visible(fn (): bool => filled($this->record->track_id))
                ->action(function () {
                    $result = app(\App\Services\DgiiSubmissionService::class)->checkStatus((string) $this->record->track_id);
                    \Filament\Notifications\Notification::make()
                        ->title($result['ok'] ? 'Estado consultado' : 'Error consultando estado')
                        ->body(json_encode($result['response'] ?? $result, JSON_UNESCAPED_UNICODE))
                        ->{$result['ok'] ? 'success' : 'warning'}()->send();
                }),
        ];
    }
}
