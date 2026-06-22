<?php

namespace App\Support;

final class RevenueMath
{
    public static function round2(float $n): float
    {
        return round($n * 100) / 100;
    }

    /** @param array{cash_in: float|int, voucher_in: float|int, voucher_out: float|int} $record */
    public static function computedNet(array $record): float
    {
        return self::round2(
            (float) $record['cash_in'] + (float) $record['voucher_in'] - (float) $record['voucher_out']
        );
    }

    public static function tierFromAmount(float $amount): string
    {
        $a = abs($amount);
        if ($a <= 75) {
            return 'minor';
        }
        if ($a <= 500) {
            return 'moderate';
        }

        return 'severe';
    }
}
