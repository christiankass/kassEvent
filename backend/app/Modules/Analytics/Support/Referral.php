<?php

namespace App\Modules\Analytics\Support;

/**
 * Source d'une visite de la page publique, lue dans le lien partagé (?src=whatsapp) ou devinée par
 * la page à partir du site d'où vient le visiteur. Liste fermée : une valeur inconnue devient « other ».
 */
final class Referral
{
    public const DIRECT = 'direct';

    public const OTHER = 'other';

    public const SOURCES = [
        'whatsapp', 'facebook', 'instagram', 'tiktok', 'linkedin', 'messenger',
        'email', 'sms', 'qr', 'link', 'search', self::OTHER, self::DIRECT,
    ];

    public static function normalize(mixed $source): string
    {
        if (! is_string($source) || trim($source) === '') {
            return self::DIRECT;
        }
        $source = strtolower(trim($source));

        return in_array($source, self::SOURCES, true) ? $source : self::OTHER;
    }
}
