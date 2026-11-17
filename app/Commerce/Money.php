<?php

namespace App\Commerce;

use InvalidArgumentException;

final class Money
{
    public static function parse(string|int $value): int
    {
        if (! preg_match('/\A(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,2}))?\z/', (string) $value, $parts)) {
            throw new InvalidArgumentException('Usa un importe positivo con hasta dos decimales.');
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function format(int $minor): string
    {
        return '$'.number_format(intdiv($minor, 100), 0, '.', ',').'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
