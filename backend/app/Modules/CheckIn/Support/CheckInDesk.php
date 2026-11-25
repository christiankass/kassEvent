<?php

namespace App\Modules\CheckIn\Support;

use App\Models\User;
use App\Modules\CheckIn\Models\TicketCheckIn;
use App\Modules\CheckIn\Models\TicketScan;
use App\Modules\Events\Models\Event;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Models\Ticket;
use App\Modules\Ticketing\Support\TicketCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Décide du résultat d'un scan et l'enregistre.
 *
 * Le premier scan fait foi : un scan fait hors ligne à 19 h 02 et synchronisé à 19 h 30 l'emporte
 * sur un scan en ligne de 19 h 10. Le scan évincé devient « déjà utilisé » et le téléphone qui l'a fait
 * alerte l'agent. Un même scan renvoyé (réseau coupé pendant la réponse) n'est compté qu'une fois.
 */
final class CheckInDesk
{
    /** Un scan hors ligne ne peut pas dater de plus d'un jour avant le début de l'événement (ou avant maintenant). */
    private const EARLIEST_BEFORE_START_HOURS = 24;

    /**
     * @param  array{id: string, code: string, scanned_at?: string|null, gate?: string|null, offline?: bool, admitted?: bool}  $scan
     * @return array<string, mixed>
     */
    public function record(Event $event, User $agent, array $scan): array
    {
        $existing = $this->find($event, $scan['id']);
        if ($existing) {
            return $this->describe($event, $existing);
        }

        $offline = (bool) ($scan['offline'] ?? false);
        $code = TicketCode::normalize($scan['code']);
        // Invitation annulée (l'invité s'est désisté) : son code ne vaut plus rien.
        $ticket = $code === '' ? null : Ticket::where('code', $code)->whereHas('order', fn ($q) => $q->where('status', Order::PAID))->first();

        $result = match (true) {
            $ticket === null => TicketScan::INVALID,
            $ticket->event_id !== $event->id => TicketScan::OTHER_EVENT,
            default => TicketScan::VALID,
        };
        // Seul un scan hors ligne peut avoir été refusé par l'agent ; en ligne, le serveur décide.
        $admitted = ! $offline || ($scan['admitted'] ?? true);

        try {
            $record = DB::transaction(function () use ($event, $agent, $scan, $ticket, $result, $offline, $admitted) {
                $record = TicketScan::create([
                    'event_id' => $event->id,
                    'ticket_id' => $ticket?->id,
                    'user_id' => $agent->id,
                    'client_id' => $scan['id'],
                    'code' => Str::limit($scan['code'], 64, ''),
                    'result' => $result,
                    'gate' => $scan['gate'] ?? null,
                    'offline' => $offline,
                    'admitted' => $admitted,
                    'scanned_at' => $this->scannedAt($event, $offline ? ($scan['scanned_at'] ?? null) : null),
                ]);

                if ($result === TicketScan::VALID && $admitted) {
                    $this->claim($record);
                }

                return $record;
            });
        } catch (UniqueConstraintViolationException) {
            // Le même scan, envoyé deux fois en même temps (réponse en ligne trop lente puis synchro).
            $record = $this->find($event, $scan['id']) ?? throw new \LogicException('Scan introuvable après un doublon.');
        }

        return $this->describe($event, $record);
    }

    private function find(Event $event, string $clientId): ?TicketScan
    {
        return TicketScan::where('event_id', $event->id)->where('client_id', $clientId)->first();
    }

    /**
     * Heure qui départage les scans : celle du serveur en ligne ; hors ligne, celle du téléphone,
     * ni dans le futur ni bien avant l'événement (une horloge déréglée ne fait pas gagner un scan).
     */
    private function scannedAt(Event $event, ?string $claimed): Carbon
    {
        $now = Carbon::now();
        if ($claimed === null) {
            return $now;
        }

        $earliest = Carbon::instance($event->starts_at->lessThan($now) ? $event->starts_at : $now)->subHours(self::EARLIEST_BEFORE_START_HOURS);
        $time = Carbon::parse($claimed);

        return match (true) {
            $time->greaterThan($now) => $now,
            $time->lessThan($earliest) => $earliest,
            default => $time,
        };
    }

    /**
     * Inscrit l'entrée du billet si ce scan est le premier, y compris face à un scan plus tardif déjà
     * enregistré. La base arbitre deux scans simultanés (une seule ligne par billet), et cette ligne
     * seule dit quel scan est « valide » : les autres scans du billet sont « déjà utilisé ».
     */
    private function claim(TicketScan $scan): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO ticket_check_ins (id, organization_id, event_id, ticket_id, scan_id, scanned_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, now(), now())
            ON CONFLICT (ticket_id) DO UPDATE
                SET scan_id = EXCLUDED.scan_id, scanned_at = EXCLUDED.scanned_at, updated_at = now()
                WHERE ticket_check_ins.scanned_at > EXCLUDED.scanned_at
            SQL, [(string) Str::uuid7(), $scan->organization_id, $scan->event_id, $scan->ticket_id, $scan->id, $scan->scanned_at]);
    }

    /** @return array<string, mixed> */
    private function describe(Event $event, TicketScan $scan): array
    {
        $ticket = $scan->ticket_id ? Ticket::with('item')->find($scan->ticket_id) : null;
        $ours = $ticket && $ticket->event_id === $event->id;
        $checkIn = $ours ? TicketCheckIn::with('scan.user')->where('ticket_id', $ticket->id)->first() : null;

        $result = match (true) {
            ! $ours => $scan->result,
            $checkIn?->scan_id === $scan->id => TicketScan::VALID,
            // Refusé hors ligne alors que personne n'est entré avec ce billet : il reste valable.
            ! $scan->admitted && $checkIn === null => TicketScan::VALID,
            default => TicketScan::ALREADY_USED,
        };

        return [
            'id' => $scan->client_id,
            'result' => $result,
            'admitted' => $scan->admitted,
            'ticket' => $ours ? self::ticket($ticket) : null,
            'first_scan' => $result === TicketScan::ALREADY_USED && $checkIn ? self::firstScan($checkIn->scan) : null,
            'other_event' => $result === TicketScan::OTHER_EVENT ? Event::whereKey($ticket?->event_id)->value('title') : null,
        ];
    }

    /** @return array{holder_name: string, ticket_type: string|null, companions: int, seat: string|null} */
    public static function ticket(Ticket $ticket): array
    {
        return [
            'holder_name' => $ticket->holder_name,
            'ticket_type' => $ticket->item?->name,
            'companions' => (int) $ticket->companions,
            // Table de l'invité (événement privé avec plan de salle) : l'agent l'indique à l'entrée.
            'seat' => $ticket->seat,
        ];
    }

    /** @return array{scanned_at: string, gate: string|null, agent: string|null} */
    public static function firstScan(TicketScan $scan): array
    {
        return [
            'scanned_at' => $scan->scanned_at->toIso8601ZuluString(),
            'gate' => $scan->gate,
            'agent' => $scan->user?->name,
        ];
    }
}
