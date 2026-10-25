<?php

namespace App\Support;

/**
 * ITBIS RD. Por defecto se SUMA al precio (comportamiento histórico).
 * Si el precio ya lo incluye, se extrae el impuesto y el cliente paga el precio de góndola.
 */
final class Itbis
{
    /**
     * @return array{net: float, tax: float, gross: float}
     */
    public static function split(float $amount, float $ratePercent, bool $included): array
    {
        $amount = round(max(0, $amount), 2);
        $rate = max(0, $ratePercent) / 100;

        if ($rate <= 0) {
            return ['net' => $amount, 'tax' => 0.0, 'gross' => $amount];
        }

        if ($included) {
            $gross = $amount;
            $net = round($gross / (1 + $rate), 2);
            $tax = round($gross - $net, 2);

            return ['net' => $net, 'tax' => $tax, 'gross' => $gross];
        }

        $net = $amount;
        $tax = round($net * $rate, 2);
        $gross = round($net + $tax, 2);

        return ['net' => $net, 'tax' => $tax, 'gross' => $gross];
    }

    /**
     * @param  list<array{amount: float, included: bool}>  $lines
     * @return array{subtotal: float, discount: float, tax: float, total: float}
     */
    public static function totalsFromLines(array $lines, float $discount, float $ratePercent): array
    {
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $subtotal += (float) ($line['amount'] ?? 0);
        }
        $subtotal = round($subtotal, 2);
        $discount = round(max(0, $discount), 2);

        $tax = 0.0;
        $total = 0.0;
        foreach ($lines as $line) {
            $amount = (float) ($line['amount'] ?? 0);
            $share = $subtotal > 0 ? $amount / $subtotal : 0.0;
            $base = max(0, $amount - $discount * $share);
            $parts = self::split($base, $ratePercent, (bool) ($line['included'] ?? false));
            $tax += $parts['tax'];
            $total += $parts['gross'];
        }

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => round($tax, 2),
            'total' => round($total, 2),
        ];
    }
}
