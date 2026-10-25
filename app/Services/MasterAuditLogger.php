<?php

namespace App\Services;

use App\Models\MasterAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Fachada de auditoría Master (delega a MasterAuditLog::log).
 */
class MasterAuditLogger
{
    public function log(
        string $action,
        ?Model $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $userId = null,
    ): ?MasterAuditLog {
        // Si se fuerza userId sin auth (jobs/tests), crear directamente
        if ($userId && ! auth()->id()) {
            try {
                return MasterAuditLog::create([
                    'user_id' => $userId,
                    'action' => $action,
                    'model_type' => $subject ? $subject::class : null,
                    'model_id' => $subject?->getKey() ? (string) $subject->getKey() : null,
                    'old_values' => $oldValues,
                    'new_values' => $newValues,
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

        return MasterAuditLog::log(
            $action,
            $subject,
            $oldValues ?? [],
            $newValues ?? [],
        );
    }
}
