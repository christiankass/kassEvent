<?php

namespace App\Modules\Events\Support;

/** Calculs de contraste WCAG : le texte posé sur une couleur choisie par l'organisateur reste lisible. */
final class Color
{
    public const DARK = '#0E1B1C';

    public const LIGHT = '#FFFFFF';

    /** Blanc ou encre, selon ce qui contraste le plus avec le fond. */
    public static function textOn(string $background): string
    {
        return self::contrast($background, self::LIGHT) >= self::contrast($background, self::DARK) ? self::LIGHT : self::DARK;
    }

    /** Teinte très pâle de la couleur (mélange avec du blanc), pour le fond de la zone du QR code. */
    public static function pale(string $hex, float $white = 0.85): string
    {
        return '#'.implode('', array_map(
            fn (int $channel) => sprintf('%02X', (int) round($channel + (255 - $channel) * $white)),
            self::rgb($hex),
        ));
    }

    public static function contrast(string $a, string $b): float
    {
        [$light, $dark] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($light + 0.05) / ($dark + 0.05);
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $channel) {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }
}
