<?php

namespace App\Modules\CheckIn\Support;

use App\Modules\Ticketing\Models\Ticket;
use Illuminate\Support\Facades\Cache;

/**
 * Empreinte d'un code de billet, gardée sur le téléphone de l'agent pour scanner sans réseau.
 *
 * PBKDF2-SHA256 avec un sel propre à l'événement : le téléphone calcule l'empreinte du code lu
 * en quelques millisecondes, mais retrouver un code à partir d'une liste copiée coûte des milliers
 * de fois plus cher qu'un simple SHA-256. Le calcul est gardé en cache (le code ne change jamais).
 */
final class TicketFingerprint
{
    public const ITERATIONS = 2000;

    /** Sel de l'événement, dérivé de la clé de l'application : rien à stocker. */
    public static function salt(string $eventId): string
    {
        return substr(hash_hmac('sha256', 'check-in|'.$eventId, (string) config('app.key')), 0, 32);
    }

    public static function of(Ticket $ticket): string
    {
        return Cache::rememberForever(
            "check-in:fingerprint:{$ticket->id}",
            fn () => hash_pbkdf2('sha256', $ticket->code, self::salt($ticket->event_id), self::ITERATIONS),
        );
    }
}
