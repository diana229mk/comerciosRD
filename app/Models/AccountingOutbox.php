<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AccountingOutbox extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $table = 'accounting_outbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'publish_attempts' => 'integer',
            'processing_attempts' => 'integer',
            'manual_retries' => 'integer',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['processed', 'skipped', 'failed'], true);
    }
}
