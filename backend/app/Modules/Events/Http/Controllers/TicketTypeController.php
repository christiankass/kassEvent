<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Http\Requests\TicketTypeRequest;
use App\Modules\Events\Http\Resources\TicketTypeResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\TicketType;
use App\Modules\Events\Support\Money;
use App\Modules\Ticketing\Support\Inventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Types de billets d'un événement de l'organisation courante (events.manage). */
final class TicketTypeController
{
    public function store(TicketTypeRequest $request, string $eventId): JsonResponse
    {
        // Verrou sur l'événement : deux ajouts simultanés ne dépassent pas la capacité ensemble.
        return DB::transaction(function () use ($request, $eventId) {
            $event = Event::with('ticketTypes')->lockForUpdate()->findOrFail($eventId);
            $attributes = self::checked($request, $event);

            $ticketType = $event->ticketTypes()->create([
                ...$attributes,
                'position' => ($event->ticketTypes->max('position') ?? -1) + 1,
            ]);

            return (new TicketTypeResource($ticketType, $event->timezone))->response()->setStatusCode(201);
        });
    }

    public function update(TicketTypeRequest $request, string $id): TicketTypeResource
    {
        return DB::transaction(function () use ($request, $id) {
            $event = Event::with('ticketTypes')->lockForUpdate()->findOrFail(self::find($id)->event_id);
            // Verrou sur le type de billet, comme à la commande : les places prises ne bougent plus pendant le contrôle.
            $ticketType = TicketType::lockForUpdate()->findOrFail($id);
            $attributes = self::checked($request, $event, except: $ticketType);
            $taken = Inventory::taken([$ticketType->id])[$ticketType->id] ?? 0;

            if ($attributes['quantity'] < $taken) {
                throw ValidationException::withMessages(['quantity' => __('ticketing.quantity_below_taken', ['count' => $taken])]);
            }
            $ticketType->update($attributes);

            return new TicketTypeResource($ticketType, $event->timezone, $taken);
        });
    }

    /** Un événement publié garde au moins un type de billet ; un billet déjà commandé ne se supprime plus. */
    public function destroy(string $id): Response
    {
        $ticketType = self::find($id);

        if ($ticketType->event->isPublished() && $ticketType->event->ticketTypes()->count() === 1) {
            throw ValidationException::withMessages(['ticket_type' => __('events.keep_one_ticket')]);
        }
        if (Inventory::everOrdered($ticketType->id)) {
            throw ValidationException::withMessages(['ticket_type' => __('ticketing.ticket_type_ordered')]);
        }

        $ticketType->delete();

        return response()->noContent();
    }

    /** Type de billet d'un événement existant (un brouillon supprimé emporte ses billets). */
    private static function find(string $id): TicketType
    {
        return TicketType::whereHas('event')->findOrFail($id);
    }

    /**
     * Attributs validés, plus deux règles liées à l'événement : le franc congolais n'a pas de
     * centimes, et la somme des quantités ne dépasse pas la capacité du lieu, si elle est indiquée.
     *
     * @return array<string, mixed>
     */
    private static function checked(TicketTypeRequest $request, Event $event, ?TicketType $except = null): array
    {
        $attributes = $request->toAttributes($event->timezone);

        if (! Money::allowsCents($event->currency) && $attributes['price'] % 100 !== 0) {
            throw ValidationException::withMessages(['price' => __('events.cdf_no_cents')]);
        }

        if ($event->capacity !== null) {
            $others = $event->ticketTypes->reject(fn (TicketType $t) => $t->is($except))->sum('quantity');

            if ($others + $attributes['quantity'] > $event->capacity) {
                throw ValidationException::withMessages([
                    'quantity' => __('events.over_capacity', ['capacity' => $event->capacity, 'left' => max(0, $event->capacity - $others)]),
                ]);
            }
        }

        return $attributes;
    }
}
