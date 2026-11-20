<?php

namespace App\Support;

/**
 * Captures security-sensitive variables from the real process environment
 * before Laravel loads any .env file, so a dotenv file can never grant them.
 */
final class ProcessEnvironment
{
    public const KEYS = [
        TestRuntime::VARIABLE,
        DatabaseTargetPolicy::APPROVAL_VARIABLE,
    ];

    /** @var array<string, string|null>|null */
    private static ?array $captured = null;

    public static function capture(): void
    {
        if (self::$captured !== null) {
            return;
        }

        $values = [];
        foreach (self::KEYS as $key) {
            $value = getenv($key);
            $values[$key] = is_string($value) ? $value : null;
        }

        self::$captured = $values;
    }

    public static function get(string $key): ?string
    {
        self::capture();

        return self::$captured[$key] ?? null;
    }

    /**
     * @internal Test seam; null restores capture from the real process environment.
     *
     * @param  array<string, string|null>|null  $values
     */
    public static function fake(?array $values): void
    {
        self::$captured = $values === null ? null : array_merge(array_fill_keys(self::KEYS, null), $values);
    }
}
