<?php

namespace App\Services;

use App\Support\FaultTypes;
use App\Support\RevenueMath;

class ImportValidationService
{
    public function validateImportShape(mixed $record): bool
    {
        if (! is_array($record)) {
            return false;
        }

        foreach (['location_id', 'location_name', 'machine_id', 'report_date'] as $field) {
            if (! isset($record[$field]) || ! is_string($record[$field])) {
                return false;
            }
        }

        foreach (['cash_in', 'voucher_in', 'voucher_out', 'net_revenue'] as $field) {
            if (! isset($record[$field]) || ! is_numeric($record[$field])) {
                return false;
            }
        }

        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $record['report_date']);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{computed_net_revenue: float, faults: list<array<string, mixed>>}
     */
    public function validateImportRecord(array $record, ?string $locationNameDb): array
    {
        $faults = [];
        $computed = RevenueMath::computedNet($record);

        if ($locationNameDb !== null && (string) $record['location_name'] !== $locationNameDb) {
            $faults[] = [
                'fault_type' => FaultTypes::LOCATION_NAME_MISMATCH,
                'severity' => 'minor',
                'expected_value' => null,
                'reported_value' => null,
                'delta' => 0,
                'description' => 'location_name "'.$record['location_name'].'" does not match "'.$locationNameDb.'"',
            ];
        }

        foreach (['cash_in' => $record['cash_in'], 'voucher_in' => $record['voucher_in'], 'voucher_out' => $record['voucher_out']] as $field => $val) {
            $val = (float) $val;
            if ($val < 0) {
                $faults[] = [
                    'fault_type' => FaultTypes::NEGATIVE_COMPONENT,
                    'severity' => 'moderate',
                    'expected_value' => 0,
                    'reported_value' => $val,
                    'delta' => abs($val),
                    'description' => "{$field} is negative ({$val})",
                ];
            }
        }

        $cashIn = (float) $record['cash_in'];
        $voucherIn = (float) $record['voucher_in'];
        $voucherOut = (float) $record['voucher_out'];
        $netRevenue = (float) $record['net_revenue'];

        if ($voucherOut > $cashIn + $voucherIn + 0.01) {
            $faults[] = [
                'fault_type' => FaultTypes::VOUCHER_OUT_EXCEEDS_INFLOW,
                'severity' => 'severe',
                'expected_value' => $cashIn + $voucherIn,
                'reported_value' => $voucherOut,
                'delta' => RevenueMath::round2($voucherOut - $cashIn - $voucherIn),
                'description' => 'voucher_out exceeds cash_in + voucher_in',
            ];
        }

        if ($cashIn > 0 && $netRevenue === 0.0) {
            $faults[] = [
                'fault_type' => FaultTypes::ZERO_NET_WITH_ACTIVITY,
                'severity' => 'moderate',
                'expected_value' => $computed,
                'reported_value' => 0,
                'delta' => abs($computed),
                'description' => 'cash_in > 0 but net_revenue is zero',
            ];
        }

        $swappedNet = RevenueMath::round2($cashIn + $voucherOut - $voucherIn);
        if (abs($swappedNet - $netRevenue) < 0.01 && abs($computed - $netRevenue) > 0.01) {
            $faults[] = [
                'fault_type' => FaultTypes::COMPONENT_SWAP,
                'severity' => 'moderate',
                'expected_value' => $computed,
                'reported_value' => $netRevenue,
                'delta' => RevenueMath::round2(abs($computed - $netRevenue)),
                'description' => 'voucher_in and voucher_out appear swapped',
            ];
        }

        $delta = RevenueMath::round2($netRevenue - $computed);
        $absDelta = abs($delta);

        if ($absDelta > 0.01) {
            if ($absDelta > 0.05 && $absDelta < 1) {
                $faults[] = [
                    'fault_type' => FaultTypes::ROUNDING_DRIFT,
                    'severity' => 'minor',
                    'expected_value' => $computed,
                    'reported_value' => $netRevenue,
                    'delta' => $absDelta,
                    'description' => 'net_revenue drifts from formula by $'.number_format($absDelta, 2),
                ];
            } elseif ($absDelta >= 1) {
                $faults[] = [
                    'fault_type' => FaultTypes::ARITHMETIC_MISMATCH,
                    'severity' => RevenueMath::tierFromAmount($absDelta),
                    'expected_value' => $computed,
                    'reported_value' => $netRevenue,
                    'delta' => $absDelta,
                    'description' => "net_revenue ({$netRevenue}) != cash_in + voucher_in - voucher_out ({$computed})",
                ];
                if ($delta < -1) {
                    $faults[] = [
                        'fault_type' => FaultTypes::UNDERREPORTED_NET,
                        'severity' => RevenueMath::tierFromAmount($absDelta),
                        'expected_value' => $computed,
                        'reported_value' => $netRevenue,
                        'delta' => $absDelta,
                        'description' => 'Under-reported net_revenue by $'.number_format($absDelta, 2),
                    ];
                } elseif ($delta > 1) {
                    $faults[] = [
                        'fault_type' => FaultTypes::OVERREPORTED_NET,
                        'severity' => RevenueMath::tierFromAmount($absDelta),
                        'expected_value' => $computed,
                        'reported_value' => $netRevenue,
                        'delta' => $absDelta,
                        'description' => 'Over-reported net_revenue by $'.number_format($absDelta, 2),
                    ];
                }
            }
        }

        return ['computed_net_revenue' => $computed, 'faults' => $faults];
    }
}
