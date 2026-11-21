<?php

namespace App\Modules\Ticketing\Support;

/**
 * Code d'un billet, contenu de son QR code : 12 caractères tirés au hasard (60 bits),
 * sans lettres ambiguës (pas de I, L, O, U), affiché par groupes de 4 : « 7KQ2-M9XD-4TPB ».
 */
final class TicketCode
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(int $length = 12): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, 31)];
        }

        return $code;
    }

    public static function format(string $code): string
    {
        return implode('-', str_split($code, 4));
    }
}
