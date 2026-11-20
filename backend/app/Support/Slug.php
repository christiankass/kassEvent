<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Slug
{
    /** Identifiant d'URL lisible et unique : texte simplifié, tronqué, plus 6 caractères au hasard. */
    public static function unique(string $text, int $length, string $fallback): string
    {
        $base = trim(Str::limit(Str::slug($text), $length, ''), '-') ?: $fallback;

        return $base.'-'.Str::lower(Str::random(6));
    }
}
