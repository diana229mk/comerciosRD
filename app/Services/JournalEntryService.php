<?php

namespace App\Services;

use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Publicación y validación de asientos contables.
 */
class JournalEntryService
{
    /**
     * Publica un asiento en borrador (debe estar cuadrado y con al menos 2 líneas).
     */
    public function publish(JournalEntry $entry): JournalEntry
    {
        return DB::transaction(function () use ($entry): JournalEntry {
            /** @var JournalEntry $locked */
            $locked = JournalEntry::query()
                ->whereKey($entry->getKey())
                ->lockForUpdate()
                ->with('lines')
                ->firstOrFail();

            if ($locked->isPosted()) {
                throw new RuntimeException('Este asiento ya está publicado.');
            }

            $locked->recalculateTotals();
            $locked->refresh();

            if ($locked->lines->count() < 2) {
                throw new RuntimeException('El asiento necesita al menos 2 líneas.');
            }

            if (! $locked->isBalanced()) {
                throw new RuntimeException(
                    'El asiento no está cuadrado. Débito: '
                    .number_format((float) $locked->total_debit, 2)
                    .' · Crédito: '
                    .number_format((float) $locked->total_credit, 2)
                );
            }

            $locked->forceFill([
                'status' => JournalEntry::STATUS_POSTED,
            ])->save();

            return $locked->fresh(['lines.account', 'creator']);
        });
    }

    /**
     * Vuelve un asiento a borrador (solo si está publicado y se permite).
     */
    public function unpublish(JournalEntry $entry): JournalEntry
    {
        if (! $entry->isPosted()) {
            throw new RuntimeException('Solo se pueden despublicar asientos publicados.');
        }

        $entry->forceFill([
            'status' => JournalEntry::STATUS_DRAFT,
        ])->save();

        return $entry->fresh();
    }
}
