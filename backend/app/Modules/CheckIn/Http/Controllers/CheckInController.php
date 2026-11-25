<?php

namespace App\Modules\CheckIn\Http\Controllers;

use App\Modules\CheckIn\Http\Requests\SyncScansRequest;
use App\Modules\CheckIn\Models\TicketCheckIn;
use App\Modules\CheckIn\Support\CheckInDesk;
use App\Modules\CheckIn\Support\TicketFingerprint;
use App\Modules\Events\Models\Event;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Models\Ticket;
use Illuminate\Http\JsonResponse;

/** Contrôle d'entrée d'un événement de l'organisation courante (permission checkin.scan). */
final class CheckInController
{
    /**
     * Liste des billets à garder sur le téléphone pour scanner sans réseau. Les codes n'y figurent
     * pas en clair, seulement leur empreinte (voir TicketFingerprint), avec le sel et le nombre de tours
     * qui permettent au téléphone de calculer celle du code qu'il lit.
     */
    public function show(string $id): JsonResponse
    {
        $event = Event::findOrFail($id);

        $checkIns = TicketCheckIn::with('scan.user')->where('event_id', $event->id)->get()->keyBy('ticket_id');
        $tickets = Ticket::with('item')->where('event_id', $event->id)
            ->whereHas('order', fn ($q) => $q->where('status', Order::PAID)) // sans les invitations annulées
            ->orderBy('id')->get();
        $fingerprints = TicketFingerprint::forTickets($event->id, $tickets);
        $tickets = $tickets->map(fn (Ticket $ticket) => [
            'hash' => $fingerprints[$ticket->id],
            ...CheckInDesk::ticket($ticket),
            'first_scan' => ($checkIn = $checkIns->get($ticket->id)) ? CheckInDesk::firstScan($checkIn->scan) : null,
        ]);

        return response()->json([
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at_utc' => $event->starts_at->toIso8601String(),
                'timezone' => $event->timezone,
                'venue_name' => $event->venue_name,
            ],
            'fingerprint' => ['salt' => TicketFingerprint::salt($event->id), 'iterations' => TicketFingerprint::ITERATIONS],
            'tickets' => $tickets,
            'checked_in' => $checkIns->count(),
            'generated_at' => now()->toIso8601ZuluString(),
        ]);
    }

    /** Enregistre des scans et renvoie le résultat qui fait foi pour chacun. */
    public function scans(SyncScansRequest $request, string $id, CheckInDesk $desk): JsonResponse
    {
        $event = Event::findOrFail($id);

        return response()->json([
            'data' => array_map(fn (array $scan) => $desk->record($event, $request->user(), $scan), $request->validated('scans')),
        ]);
    }
}
