<?php

namespace App\Filament\Resources\JournalEntryResource\Pages;

use App\Filament\Resources\JournalEntryResource;
use App\Services\JournalEntryService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditJournalEntry extends EditRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('publicar')
                ->label('Publicar asiento')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->isDraft()
                    && JournalEntryResource::canEdit($this->record))
                ->action(function (): void {
                    try {
                        // Guardar líneas del form antes de publicar
                        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                        app(JournalEntryService::class)->publish($this->record->fresh());
                        Notification::make()
                            ->title('Asiento publicado')
                            ->success()
                            ->send();
                        $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('No se pudo publicar')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn (): bool => $this->record->isDraft()),
        ];
    }

    protected function afterSave(): void
    {
        $this->record->recalculateTotals();
        $this->record->refresh();

        if ($this->record->lines()->count() < 2) {
            throw ValidationException::withMessages([
                'lines' => 'El asiento necesita al menos 2 líneas.',
            ]);
        }
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        if ($this->record->isPosted()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
        }
    }
}
