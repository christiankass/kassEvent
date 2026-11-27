<?php

namespace App\Modules\Events\Support;

use Closure;

/** Textes libres écrits par le public (livre d'or, mot d'une contribution) : sans liens, nettoyés. */
final class PlainText
{
    /** Règle de validation : pas d'adresse web (spam). */
    public static function noLinks(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && preg_match('~(https?://|www\.|\b[a-z0-9-]+\.(com|net|org|info|xyz|ru|top|link|click|ly)\b)~i', $value)) {
                $fail(__('events.wishes_no_links'));
            }
        };
    }

    /** Espaces en trop, caractères de contrôle et lignes vides répétées retirés. */
    public static function clean(string $text, bool $singleLine = false): string
    {
        // Caractères de contrôle (sauf retour à la ligne) et inversions de sens d'écriture.
        $text = preg_replace('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', str_replace("\r\n", "\n", $text)) ?? '';
        $text = $singleLine ? preg_replace('/\s+/u', ' ', $text) : preg_replace("/\n{3,}/", "\n\n", $text);

        return trim((string) $text);
    }
}
