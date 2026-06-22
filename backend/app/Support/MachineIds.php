<?php

namespace App\Support;

final class MachineIds
{
    public static function formatMachineId(int $locNum, int $seq): string
    {
        return sprintf('VGT-%d%s', $locNum, str_pad((string) $seq, 3, '0', STR_PAD_LEFT));
    }
}
