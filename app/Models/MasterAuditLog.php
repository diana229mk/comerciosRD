<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Auditoría append-only de acciones del Panel Master.
 * Separada de audit_logs (multi-tenant / seguridad general).
 */
class MasterAuditLog extends Model
{
    use HasUuids;

    protected $table = 'master_audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'url',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'model_type', 'model_id');
    }

    /**
     * Registrar una acción en el Audit Log del Master (fail-safe).
     */
    public static function log(
        string $action,
        ?Model $model = null,
        array $oldValues = [],
        array $newValues = [],
    ): ?self {
        try {
            $user = auth()->user();

            if (! $user) {
                return null;
            }

            return self::create([
                'user_id' => $user->getKey(),
                'action' => $action,
                'model_type' => $model ? $model::class : null,
                'model_id' => $model?->getKey() ? (string) $model->getKey() : null,
                'old_values' => $oldValues ?: null,
                'new_values' => $newValues ?: null,
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 1000),
                'url' => substr((string) request()->fullUrl(), 0, 500),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Impedir updates/deletes en runtime (append-only).
     */
    protected static function booted(): void
    {
        static::updating(function () {
            return false;
        });

        static::deleting(function () {
            return false;
        });
    }
}
