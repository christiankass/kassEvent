<?php

namespace App\Modules\CheckIn\Support;

use App\Models\User;
use App\Modules\CheckIn\Models\TicketCheckIn;
use App\Modules\CheckIn\Models\TicketScan;
use App\Modules\Events\Models\Event;
use App\Modules\Ticketing\Models\Ticket;
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
    /**
     * @param  array{id: string, code: string, scanned_at?: string|null, gate?: string|null, offline?: bool}  $scan
     * @return array<string, mixed>
     */
    public function record(Event $event, User $agent, array $scan): array
    {
        $existing = TicketScan::where('event_id', $event->id)->where('client_id', $scan['id'])->first();
        if ($existing) {
            return $this->describe($event, $existing);
        }

        $now = Carbon::now();
        $scannedAt = isset($scan['scanned_at']) ? Carbon::parse($scan['scanned_at']) : $now;
        $code = self::normalize($scan['code']);
        $ticket = $code === '' ? null : Ticket::where('code', $code)->first();

        $result = match (true) {
            $ticket === null => TicketScan::INVALID,
            $ticket->event_id !== $event->id => TicketScan::OTHER_EVENT,
            default => TicketScan::VALID,
        };

        $record = DB::transaction(function () use ($event, $agent, $scan, $ticket, $result, $scannedAt, $now) {
            $record = TicketScan::create([
                'event_id' => $event->id,
                'ticket_id' => $ticket?->id,
                'user_id' => $agent->id,
                'client_id' => $scan['id'],
                'code' => Str::limit($scan['code'], 64, ''),
                'result' => $result,
                'gate' => $scan['gate'] ?? null,
                'offline' => (bool) ($scan['offline'] ?? false),
                // Une horloge de téléphone en avance ne doit pas faire gagner un scan.
                'scanned_at' => $scannedAt->greaterThan($now) ? $now : $scannedAt,
            ]);

            if ($result === TicketScan::VALID && ! $this->claim($record)) {
                $record->update(['result' => TicketScan::ALREADY_USED]);
            }

            return $record;
        });

        return $this->describe($event, $record);
    }

    /** Contenu d'un QR code ou code saisi à la main (« 7kq2-m9xd-4tpb ») ramené au code du billet. */
    public static function normalize(string $code): string
    {
        $clean = preg_replace('/[^0-9A-Z]/', '', strtoupper(trim($code))) ?? '';

        return strlen($clean) <= 20 ? $clean : '';
    }

    /**
     * Inscrit l'entrée du billet si ce scan est le premier, y compris face à un scan plus tardif déjà
     * enregistré. La base arbitre deux scans simultanés (une seule ligne par billet).
     */
    private function claim(TicketScan $scan): bool
    {
        $row = DB::selectOne(<<<'SQL'
            INSERT INTO ticket_check_ins (id, organization_id, event_id, ticket_id, scan_id, scanned_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, now(), now())
            ON CONFLICT (ticket_id) DO UPDATE
                SET scan_id = EXCLUDED.scan_id, scanned_at = EXCLUDED.scanned_at, updated_at = now()
                WHERE ticket_check_ins.scanned_at > EXCLUDED.scanned_at
            RETURNING (SELECT scan_id FROM ticket_check_ins old WHERE old.ticket_id = ?) AS displaced
            SQL, [
            (string) Str::uuid7(), $scan->organization_id, $scan->event_id, $scan->ticket_id, $scan->id, $scan->scanned_at, $scan->ticket_id,
        ]);

        if ($row === null) {
            return false;
        }

        // Un scan plus tardif avait été accepté : il devient « déjà utilisé ».
        if ($row->displaced) {
            TicketScan::whereKey($row->displaced)->update(['result' => TicketScan::ALREADY_USED]);
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function describe(Event $event, TicketScan $scan): array
    {
        $ticket = $scan->ticket_id ? Ticket::with('item')->find($scan->ticket_id) : null;
        $checkIn = $ticket ? TicketCheckIn::with('scan')->where('ticket_id', $ticket->id)->first() : null;

        $result = $scan->result;
        if ($ticket && $ticket->event_id === $event->id) {
            $result = $checkIn?->scan_id === $scan->id ? TicketScan::VALID : TicketScan::ALREADY_USED;
        }

        return [
            'id' => $scan->client_id,
            'result' => $result,
            'ticket' => $ticket && $result !== TicketScan::OTHER_EVENT ? self::ticket($ticket) : null,
            'first_scan' => $result === TicketScan::ALREADY_USED && $checkIn ? self::firstScan($checkIn->scan) : null,
            'other_event' => $result === TicketScan::OTHER_EVENT
                ? Event::whereKey($ticket?->event_id)->value('title')
                : null,
        ];
    }

    /** @return array{holder_name: string, ticket_type: string|null, companions: int} */
    public static function ticket(Ticket $ticket): array
    {
        return [
            'holder_name' => $ticket->holder_name,
            'ticket_type' => $ticket->item?->name,
            'companions' => (int) $ticket->companions,
        ];
    }

    /** @return array{scanned_at: string, gate: string|null, agent: string|null} */
    public static function firstScan(TicketScan $scan): array
    {
        return [
            'scanned_at' => $scan->scanned_at->toIso8601ZuluString(),
            'gate' => $scan->gate,
            'agent' => $scan->user_id ? User::whereKey($scan->user_id)->value('name') : null,
        ];
    }
}
