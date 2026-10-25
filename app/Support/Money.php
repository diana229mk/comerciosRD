<?php

namespace App\Support;

/**
 * Formato monetario DOP (República Dominicana).
 */
final class Money
{
    public static function format(float|int|string|null $amount, bool $withSymbol = true): string
    {
        $value = number_format((float) $amount, 2, '.', ',');

        return $withSymbol ? 'RD$ '.$value : $value;
    }

    public static function compact(float|int|string|null $amount): string
    {
        $n = (float) $amount;
        $abs = abs($n);

        if ($abs >= 1_000_000) {
            $s = number_format($n / 1_000_000, 2, '.', ',').'M';
        } elseif ($abs >= 1_000) {
            $s = number_format($n / 1_000, 1, '.', ',').'K';
        } else {
            $s = number_format($n, 2, '.', ',');
        }

        return 'RD$ '.$s;
    }
}
