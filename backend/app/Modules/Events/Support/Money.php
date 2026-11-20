<?php

namespace App\Modules\Events\Support;

/** Montants saisis en unités (« 25.50 ») et enregistrés en unités mineures (2550), sans passer par un nombre à virgule. */
final class Money
{
    public const PATTERN = '/^\d{1,9}([.,]\d{1,2})?$/';

    /** Le franc congolais n'a pas de centimes en pratique : prix entiers seulement. */
    public static function allowsCents(string $currency): bool
    {
        return $currency !== 'CDF';
    }

    public static function toMinor(string $amount): int
    {
        [$units, $fraction] = array_pad(preg_split('/[.,]/', trim($amount)), 2, '');

        return (int) $units * 100 + (int) str_pad($fraction, 2, '0');
    }
}
