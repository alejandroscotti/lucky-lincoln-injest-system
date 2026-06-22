<?php

namespace App\Support;

final class FaultSimulation
{
    public static function pickFaultMode(?callable $rng = null): string
    {
        $roll = ($rng ?? fn () => lcg_value())();
        if ($roll < 0.095) {
            return 'under';
        }
        if ($roll < 0.108) {
            return 'over';
        }

        return 'clean';
    }

    /** @param array<string, mixed> $record */
    public static function corruptRecord(array $record, string $mode, ?callable $rng = null): array
    {
        if ($mode === 'clean') {
            return $record;
        }

        $rngFn = $rng ?? fn () => lcg_value();
        $computed = RevenueMath::computedNet($record);
        $copy = $record;

        if ($mode === 'under') {
            $strategies = [
                fn () => $copy['net_revenue'] = RevenueMath::round2($computed - self::randomBetween($rngFn, 10, 400)),
                function () use (&$copy) {
                    $t = $copy['voucher_out'];
                    $copy['voucher_out'] = $copy['voucher_in'];
                    $copy['voucher_in'] = $t;
                },
                fn () => $copy['net_revenue'] = RevenueMath::round2($computed * 0.7),
            ];
            $strategies[(int) floor($rngFn() * count($strategies))]();
        } else {
            $strategies = [
                fn () => $copy['net_revenue'] = RevenueMath::round2($computed + self::randomBetween($rngFn, 10, 350)),
                function () use (&$copy, $computed, $rngFn) {
                    $copy['cash_in'] = RevenueMath::round2((float) $copy['cash_in'] * 1.2);
                    $copy['net_revenue'] = RevenueMath::round2($computed + 80);
                },
            ];
            $strategies[(int) floor($rngFn() * count($strategies))]();
        }

        return $copy;
    }

    private static function randomBetween(callable $rng, float $min, float $max): float
    {
        return $min + $rng() * ($max - $min);
    }
}
