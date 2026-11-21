<?php

namespace App\Modules\Payments\Support;

/**
 * Numéros mobiles de RDC : « 0812345678 », « +243 81 234 56 78 » ou « 243812345678 »
 * deviennent « 243812345678 » (format attendu par pawaPay).
 */
final class Phone
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $digits = preg_replace('/[\s.\-()]/', '', $input);
        $digits = preg_replace('/^(\+|00)/', '', $digits);

        if (preg_match('/^0([89]\d{8})$/', $digits, $m)) {
            return '243'.$m[1];
        }
        if (preg_match('/^243([89]\d{8})$/', $digits)) {
            return $digits;
        }

        return null;
    }

    /** « 243812345678 » → « +243 81 ••• •• 78 » (affichage sans révéler tout le numéro). */
    public static function mask(?string $phone): ?string
    {
        return $phone === null ? null : '+243 '.substr($phone, 3, 2).' ••• •• '.substr($phone, -2);
    }
}
