<?php

namespace App\Modules\Guests\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Guests\Http\Requests\StoreGuestsRequest;
use App\Modules\Guests\Http\Requests\UpdateGuestRequest;
use App\Modules\Guests\Http\Resources\GuestResource;
use App\Modules\Guests\Models\Guest;
use App\Modules\Guests\Models\SeatingPlan;
use App\Modules\Guests\Support\GuestBook;
use App\Modules\Guests\Support\Seating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Invités d'un événement de l'organisation courante : liste et tableau des réponses (events.view),
 * ajout, modification, envoi des invitations (events.manage).
 */
final class GuestController
{
    public function __construct(private readonly GuestBook $book, private readonly Seating $seating) {}

    /** Tout ce qu'affiche la page Invités : invités, réponses, plan de salle et tables. */
    public function index(string $eventId): JsonResponse
    {
        $event = Event::findOrFail($eventId);
        $guests = Guest::where('event_id', $event->id)->orderBy('name')->orderBy('id')->get();
        $plan = SeatingPlan::where('event_id', $event->id)->first();

        return response()->json([
            'data' => GuestResource::collection($guests),
            'summary' => GuestBook::summary($guests),
            'plan' => $plan ? ['hall_capacity' => $plan->hall_capacity, 'seats_per_table' => $plan->seats_per_table] : null,
            'tables' => SeatingController::tablesPayload($this->seating->tables($event)),
        ]);
    }

    public function store(StoreGuestsRequest $request, string $eventId): JsonResponse
    {
        $event = Event::findOrFail($eventId);
        $guests = $this->book->add($event, $request->guests(), app()->getLocale());

        return GuestResource::collection($guests)->response()->setStatusCode(201);
    }

    public function update(UpdateGuestRequest $request, string $id): GuestResource
    {
        return new GuestResource($this->book->update($this->find($id), $request->changes()));
    }

    public function destroy(string $id): Response
    {
        $this->book->remove($this->find($id));

        return response()->noContent();
    }

    /**
     * Invitations par e-mail : aux invités choisis, ou à tous ceux qui n'ont pas encore répondu.
     * Un invité déjà relancé dans la journée n'est pas relancé de nouveau.
     */
    public function email(Request $request, string $eventId): JsonResponse
    {
        $event = Event::findOrFail($eventId);
        $data = $request->validate([
            'guest_ids' => ['required_without:pending', 'array', 'max:'.GuestBook::MAX_GUESTS],
            'guest_ids.*' => ['uuid'],
            'pending' => ['sometimes', 'boolean'],
        ]);
        $guests = Guest::where('event_id', $event->id)->with('event')
            ->when(isset($data['guest_ids']), fn ($q) => $q->whereKey($data['guest_ids']), fn ($q) => $q->where('status', Guest::PENDING))
            ->get();

        return response()->json(['sent' => $this->book->email($guests), 'selected' => $guests->count()]);
    }

    /** L'organisateur a envoyé lui-même l'invitation (WhatsApp de son téléphone, Messenger, SMS). */
    public function sent(Request $request, string $id): GuestResource
    {
        $request->validate(['channel' => ['required', Rule::in(['whatsapp', 'messenger', 'share', 'copy'])]]);

        return new GuestResource($this->book->markSent($this->find($id)));
    }

    private function find(string $id): Guest
    {
        return Guest::whereHas('event')->findOrFail($id);
    }
}
