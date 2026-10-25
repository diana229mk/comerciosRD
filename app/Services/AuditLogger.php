<?php

namespace App\Services;

use App\Models\AuditLog;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * Escritura centralizada de AuditLog (fail-safe: nunca rompe el request).
 */
class AuditLogger
{
    public function log(
        string $event,
        string $severity = 'info',
        ?string $description = null,
        array $properties = [],
        ?Model $auditable = null,
        ?string $userId = null,
        ?string $businessId = null,
    ): ?AuditLog {
        try {
            $businessId ??= $this->resolveBusinessId();
            $userId ??= auth()->id() ? (string) auth()->id() : null;

            return AuditLog::create([
                'business_id'    => $businessId,
                'user_id'        => $userId,
                'event'          => $event,
                'severity'       => $severity,
                'auditable_type' => $auditable ? $auditable::class : null,
                'auditable_id'   => $auditable?->getKey(),
                'ip_address'     => Request::ip(),
                'user_agent'     => substr((string) Request::userAgent(), 0, 1000),
                'url'            => substr((string) Request::fullUrl(), 0, 500),
                'method'         => Request::method(),
                'properties'     => $properties ?: null,
                'description'    => $description,
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function critical(
        string $event,
        string $description,
        array $properties = [],
        ?Model $auditable = null,
        ?string $userId = null,
    ): ?AuditLog {
        return $this->log($event, 'critical', $description, $properties, $auditable, $userId);
    }

    public function warning(
        string $event,
        string $description,
        array $properties = [],
        ?Model $auditable = null,
        ?string $userId = null,
    ): ?AuditLog {
        return $this->log($event, 'warning', $description, $properties, $auditable, $userId);
    }

    public function info(
        string $event,
        string $description,
        array $properties = [],
        ?Model $auditable = null,
        ?string $userId = null,
    ): ?AuditLog {
        return $this->log($event, 'info', $description, $properties, $auditable, $userId);
    }

    protected function resolveBusinessId(): ?string
    {
        try {
            $tenant = Filament::getTenant();
            if ($tenant) {
                return (string) $tenant->getKey();
            }
        } catch (\Throwable) {
        }

        if (app()->bound('currentBusinessId')) {
            $id = app('currentBusinessId');

            return $id ? (string) $id : null;
        }

        $sessionId = session('current_business_id');

        return $sessionId ? (string) $sessionId : null;
    }
}
