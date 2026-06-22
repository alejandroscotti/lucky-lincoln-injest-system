<?php

namespace App\Support;

final class Idempotency
{
    private const IDEMPOTENCY_KEY_RE = '/^(LOC-\d{3})-(\d{4})-(\d{2})-(\d{2})$/';

    public static function fileKey(string $locationId, string $reportDate): string
    {
        $date = substr($reportDate, 0, 10);

        return "{$locationId}-{$date}";
    }

    /** @return array{location_id: string, report_date: string}|null */
    public static function parseIdempotencyKey(string $key): ?array
    {
        if (! preg_match(self::IDEMPOTENCY_KEY_RE, trim($key), $m)) {
            return null;
        }

        return [
            'location_id' => $m[1],
            'report_date' => "{$m[2]}-{$m[3]}-{$m[4]}",
        ];
    }

    /** @param list<array<string, mixed>> $records */
    public static function hashPayload(array $records): string
    {
        usort($records, fn ($a, $b) => strcmp((string) $a['machine_id'], (string) $b['machine_id']));

        $canonical = array_map(fn ($r) => [
            'location_id' => $r['location_id'],
            'location_name' => $r['location_name'],
            'machine_id' => $r['machine_id'],
            'cash_in' => $r['cash_in'],
            'voucher_in' => $r['voucher_in'],
            'voucher_out' => $r['voucher_out'],
            'net_revenue' => $r['net_revenue'],
            'report_date' => substr((string) $r['report_date'], 0, 10),
        ], $records);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES));
    }

    public static function mulberry32(int $seed): callable
    {
        return function () use (&$seed) {
            $seed = ($seed + 0x6D2B79F5) & 0xFFFFFFFF;
            $t = $seed;
            $t = self::imul($t ^ ($t >> 15), $t | 1);
            $t ^= $t + self::imul($t ^ ($t >> 7), $t | 61);

            return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
        };
    }

    public static function seededRngForLocationDate(string $locationId, string $reportDate, int $baseSeed = 42): callable
    {
        return self::mulberry32(self::hashSeed("{$locationId}|{$reportDate}|{$baseSeed}"));
    }

    public static function shiftReportDate(string $reportDate, int $days): string
    {
        $d = new \DateTimeImmutable(substr($reportDate, 0, 10).'T12:00:00Z');

        return $d->modify("{$days} days")->format('Y-m-d');
    }

    private static function hashSeed(string $input): int
    {
        $h = 0;
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $h = self::imul(31, $h) + ord($input[$i]);
            $h &= 0xFFFFFFFF;
        }

        return $h & 0xFFFFFFFF;
    }

    private static function imul(int $a, int $b): int
    {
        return ($a * $b) & 0xFFFFFFFF;
    }
}
