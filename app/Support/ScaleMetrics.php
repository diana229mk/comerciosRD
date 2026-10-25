<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Temporiza cobro y búsqueda. No es APM; deja pistas cuando algo pasa de
 * SCALE_METRICS_SLOW_MS para saber cuándo partir búsqueda o el POS.
 */
final class ScaleMetrics
{
    /**
     * @template T
     * @param  callable(): T  $callback
     * @param  array<string, mixed>  $context
     * @return T
     */
    public static function time(string $name, callable $callback, array $context = []): mixed
    {
        $start = hrtime(true);

        try {
            return $callback();
        } finally {
            $ms = (hrtime(true) - $start) / 1e6;
            self::record($name, $ms, $context);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(string $name, float $ms, array $context = []): void
    {
        if (! config('scale.metrics.enabled', true)) {
            return;
        }

        $rounded = round($ms, 1);
        $payload = array_merge($context, [
            'metric' => $name,
            'ms' => $rounded,
        ]);

        $slow = (float) config('scale.metrics.slow_ms', 200);
        if ($rounded >= $slow) {
            Log::warning('scale.slow', $payload);
        }

        try {
            Cache::put('scale.metric.'.$name, [
                'ms' => $rounded,
                'at' => now()->toIso8601String(),
            ], 3600);
        } catch (Throwable) {
            // cache caída no debe afectar el cobro
        }
    }
}
