<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Log inmutable de eventos de seguridad y auditoría (DGII / compliance).
 * No usa BelongsToBusiness: es cross-tenant controlado (master + sistema).
 */
class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'user_id',
        'event',
        'severity',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'user_agent',
        'url',
        'method',
        'properties',
        'description',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
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

    /**
     * Registrar un evento en el audit log (fail-safe).
     * Nunca lanza excepción para no romper la acción original.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function log(
        string $event,
        ?Model $subject = null,
        array $properties = [],
        ?string $description = null,
        string $severity = 'info',
    ): void {
        try {
            $request = request();
            $user = $request?->user();

            static::create([
                'business_id' => (function () use ($subject) {
                    try {
                        $tenant = \Filament\Facades\Filament::getTenant();
                        if ($tenant) {
                            return (string) $tenant->getKey();
                        }
                    } catch (\Throwable) {
                    }

                    return session('current_business_id')
                        ?: ($subject?->business_id ?? null);
                })(),
                'user_id' => $user?->getKey(),
                'event' => $event,
                'severity' => $severity,
                'auditable_type' => $subject ? get_class($subject) : null,
                'auditable_id' => $subject?->getKey(),
                'ip_address' => $request?->ip(),
                'user_agent' => (string) $request?->userAgent(),
                'url' => $request?->fullUrl(),
                'method' => $request?->method(),
                'properties' => $properties,
                'description' => $description,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            logger()->warning('AuditLog::log falló', ['event' => $event, 'err' => $e->getMessage()]);
        }
    }
}
