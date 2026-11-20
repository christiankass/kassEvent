<?php

namespace App\Modules\Payments\Support;

/** Conversion entre unités mineures (2550) et montant décimal des prestataires (« 25.50 »). */
final class Amount
{
    public static function toDecimal(int $minor): string
    {
        $units = intdiv($minor, 100);
        $cents = $minor % 100;

        return $cents === 0 ? (string) $units : sprintf('%d.%02d', $units, $cents);
    }

    /** « 25 », « 25.5 », « 25.50 » → 2550 ; null si le format est inattendu. */
    public static function fromDecimal(mixed $amount): ?int
    {
        if (! is_string($amount) && ! is_int($amount)) {
            return null;
        }
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', (string) $amount, $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }
}
