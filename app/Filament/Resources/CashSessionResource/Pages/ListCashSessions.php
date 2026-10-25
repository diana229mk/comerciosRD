<?php

namespace App\Filament\Resources\CashSessionResource\Pages;

use App\Filament\Resources\CashSessionResource;
use App\Models\CashSession;
use App\Services\CashSessionService;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCashSessions extends ListRecords
{
    protected static string $resource = CashSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Abrir Caja')
                ->visible(fn (): bool => CashSessionResource::canCreate()),
        ];
    }

    public function getSubheading(): ?string
    {
        $mine = app(CashSessionService::class)->currentOpen();
        $openCount = CashSession::query()->open()->count();

        if ($openCount === 0) {
            return 'No hay cajas abiertas. Cada cajero abre su propio turno.';
        }

        $names = CashSession::query()->open()->with('user:id,name')->get()
            ->map(fn (CashSession $s) => $s->user?->name ?: 'Cajero')
            ->unique()
            ->values();

        $who = $names->implode(', ');
        if ($mine instanceof CashSession) {
            $p = app(CashSessionService::class)->preview($mine);

            return $openCount.' abierta'.($openCount === 1 ? '' : 's').' ('.$who.'). Tu turno desde '
                .$mine->opened_at?->format('d/m/Y H:i')
                .' · Esperado: RD$ '.number_format($p['expected'], 2);
        }

        return $openCount.' caja'.($openCount === 1 ? '' : 's').' abierta'.($openCount === 1 ? '' : 's').' ('.$who.'). Tú no tienes turno abierto.';
    }
}
