<?php

namespace App\Modules\Communication\Support;

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
        $compact = preg_replace('/[\s.\-()\/]/', '', $input);

        if (preg_match('/^0([89]\d{8})$/', $compact, $m)) {
            return '243'.$m[1];
        }
        // Indicatif de RDC, avec ou sans le 0 national (« +243 081… » est fréquent).
        if (preg_match('/^(?:\+|00)?2430?([89]\d{8})$/', $compact, $m)) {
            return '243'.$m[1];
        }
        // Autre pays : indicatif obligatoire, 8 à 15 chiffres (E.164), jamais de 0 en tête.
        if (preg_match('/^(?:\+|00)([1-9]\d{7,14})$/', $compact, $m)) {
            return $m[1];
        }

        return null;
    }
}
