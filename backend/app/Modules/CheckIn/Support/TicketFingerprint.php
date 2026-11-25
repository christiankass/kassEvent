<?php

namespace App\Modules\CheckIn\Support;

use App\Modules\Ticketing\Models\Ticket;
use Illuminate\Support\Collection;
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

    /**
     * Empreintes des billets d'un événement, en une lecture de cache ; seules les nouvelles sont calculées.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array<string, string> identifiant du billet => empreinte
     */
    public static function forTickets(string $eventId, Collection $tickets): array
    {
        $salt = self::salt($eventId);
        $key = fn (Ticket $ticket) => 'check-in:fingerprint:'.substr($salt, 0, 8).':'.$ticket->id;
        $cached = $tickets->isEmpty() ? [] : Cache::many($tickets->map($key)->all());

        $missing = [];
        $fingerprints = [];
        foreach ($tickets as $ticket) {
            $fingerprints[$ticket->id] = $cached[$key($ticket)]
                ?? $missing[$key($ticket)] = hash_pbkdf2('sha256', $ticket->code, $salt, self::ITERATIONS);
        }
        if ($missing !== []) {
            Cache::putMany($missing, now()->addDays(30));
        }

        return $fingerprints;
    }
}
