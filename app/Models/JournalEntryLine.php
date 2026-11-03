<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class JournalEntryLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'debit',
        'credit',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    protected static function booted(): void
    {
        static::saving(function (JournalEntryLine $line): void {
            $entry = JournalEntry::withoutBusinessScope()
                ->whereKey($line->journal_entry_id)
                ->first();
            $account = Account::withoutBusinessScope()
                ->whereKey($line->account_id)
                ->first();

            if (! $entry || ! $account) {
                throw new RuntimeException('La línea contable requiere un asiento y una cuenta existentes.');
            }

            if ((string) $entry->business_id !== (string) $account->business_id) {
                throw new RuntimeException('La cuenta contable debe pertenecer al mismo negocio que el asiento.');
            }

            $debit = max(0, (float) ($line->debit ?? 0));
            $credit = max(0, (float) ($line->credit ?? 0));

            // Una línea no debe tener débito y crédito a la vez
            if ($debit > 0 && $credit > 0) {
                if ($debit >= $credit) {
                    $credit = 0;
                } else {
                    $debit = 0;
                }
            }

            $line->debit = round($debit, 2);
            $line->credit = round($credit, 2);
        });

        static::saved(function (JournalEntryLine $line): void {
            $line->journalEntry?->recalculateTotals();
        });

        static::deleted(function (JournalEntryLine $line): void {
            $line->journalEntry?->recalculateTotals();
        });
    }
}
