<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Rango del dashboard: hoy / semana / mes / personalizado.
 *
 * @phpstan-type Period array{
 *     preset: string,
 *     label: string,
 *     from: Carbon,
 *     to: Carbon,
 *     prev_from: Carbon,
 *     prev_to: Carbon
 * }
 */
final class DashboardPeriod
{
    /**
     * @param  array<string, mixed>|null  $filters
     * @return Period
     */
    public static function fromFilters(?array $filters): array
    {
        $preset = (string) ($filters['preset'] ?? 'month');
        if (! in_array($preset, ['today', 'week', 'month', 'custom'], true)) {
            $preset = 'month';
        }

        $now = now();

        if ($preset === 'today') {
            $from = $now->copy()->startOfDay();
            $to = $now->copy()->endOfDay();
            $label = 'Hoy';
        } elseif ($preset === 'week') {
            $from = $now->copy()->startOfWeek();
            $to = $now->copy()->endOfDay();
            $label = 'Esta semana';
        } elseif ($preset === 'custom') {
            $from = filled($filters['date_from'] ?? null)
                ? Carbon::parse((string) $filters['date_from'])->startOfDay()
                : $now->copy()->startOfMonth();
            $to = filled($filters['date_to'] ?? null)
                ? Carbon::parse((string) $filters['date_to'])->endOfDay()
                : $now->copy()->endOfDay();
            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }
            $label = $from->format('d/m').' – '.$to->format('d/m/Y');
        } else {
            $from = $now->copy()->startOfMonth();
            $to = $now->copy()->endOfDay();
            $label = 'Este mes';
            $preset = 'month';
        }

        $days = max(1, (int) $from->diffInDays($to) + 1);
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();

        return [
            'preset' => $preset,
            'label' => $label,
            'from' => $from,
            'to' => $to,
            'prev_from' => $prevFrom,
            'prev_to' => $prevTo,
        ];
    }
}
