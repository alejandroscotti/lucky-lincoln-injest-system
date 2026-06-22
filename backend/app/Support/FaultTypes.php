<?php

namespace App\Support;

final class FaultTypes
{
    public const ARITHMETIC_MISMATCH = 'arithmetic_mismatch';

    public const UNDERREPORTED_NET = 'underreported_net';

    public const OVERREPORTED_NET = 'overreported_net';

    public const VOUCHER_OUT_EXCEEDS_INFLOW = 'voucher_out_exceeds_inflow';

    public const NEGATIVE_COMPONENT = 'negative_component';

    public const COMPONENT_SWAP = 'component_swap';

    public const ROUNDING_DRIFT = 'rounding_drift';

    public const ZERO_NET_WITH_ACTIVITY = 'zero_net_with_activity';

    public const LOCATION_NAME_MISMATCH = 'location_name_mismatch';

    /** @var list<string> */
    public const ALL = [
        self::UNDERREPORTED_NET,
        self::OVERREPORTED_NET,
        self::ARITHMETIC_MISMATCH,
        self::COMPONENT_SWAP,
        self::VOUCHER_OUT_EXCEEDS_INFLOW,
        self::NEGATIVE_COMPONENT,
        self::ROUNDING_DRIFT,
        self::ZERO_NET_WITH_ACTIVITY,
        self::LOCATION_NAME_MISMATCH,
    ];

    /** @var array<string, string> */
    public const LABELS = [
        self::ARITHMETIC_MISMATCH => 'Arithmetic mismatch',
        self::UNDERREPORTED_NET => 'Under-reported net',
        self::OVERREPORTED_NET => 'Over-reported net',
        self::VOUCHER_OUT_EXCEEDS_INFLOW => 'Voucher out exceeds inflow',
        self::NEGATIVE_COMPONENT => 'Negative component',
        self::COMPONENT_SWAP => 'Component swap',
        self::ROUNDING_DRIFT => 'Rounding drift',
        self::ZERO_NET_WITH_ACTIVITY => 'Zero net with activity',
        self::LOCATION_NAME_MISMATCH => 'Location name mismatch',
    ];
}
