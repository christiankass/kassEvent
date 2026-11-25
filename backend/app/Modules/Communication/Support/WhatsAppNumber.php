<?php

namespace App\Modules\Communication\Support;

use App\Modules\Payments\Support\Phone;

/**
 * Numéro WhatsApp au format international sans « + » (format de l'API de Meta).
 * Un numéro de RDC peut s'écrire à la locale (« 0812345678 ») ; un numéro d'un autre pays
 * (famille à l'étranger) commence par « + » ou « 00 ».
 */
final class WhatsAppNumber
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        // Numéro de RDC : mêmes règles que le mobile money ; « +243 081… » (0 national gardé) est fréquent.
        $compact = preg_replace('/[\s.\-()\/]/', '', $input);
        $drc = Phone::normalize(preg_replace('/^((?:\+|00)?243)0/', '$1', $compact));
        if ($drc !== null) {
            return $drc;
        }
        // Autre pays : indicatif obligatoire, 8 à 15 chiffres (E.164), jamais de 0 en tête.
        if (preg_match('/^(?:\+|00)([1-9]\d{7,14})$/', $compact, $m)) {
            return $m[1];
        }

        return null;
    }
}
