<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Http\Requests\EventRequest;
use App\Modules\Events\Http\Resources\EventResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventDesign;
use App\Modules\Events\Support\LocalDateTime;
use App\Modules\Ticketing\Support\Inventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Événements de l'organisation courante (lecture : events.view ; écriture : events.manage). */
final class EventController
{
    /** Les événements à venir d'abord, puis les passés. */
    public function index(): AnonymousResourceCollection
    {
        $events = Event::with('ticketTypes')
            ->orderByRaw('starts_at < now()')
            ->orderBy('starts_at')
            ->get();

        $taken = Inventory::taken($events->flatMap->ticketTypes->pluck('id'));

        return EventResource::collection($events->map(fn (Event $event) => EventResource::make($event)->withAvailability($taken)));
    }

    public function store(EventRequest $request): JsonResponse
    {
        $event = Event::create($request->toAttributes());

        return EventResource::make($event->setRelation('ticketTypes', collect()))->response()->setStatusCode(201);
    }

    public function show(string $id): EventResource
    {
        return EventResource::make(Event::with('ticketTypes')->findOrFail($id))->withAvailability();
    }

    /**
     * Une fois les billets créés, leurs prix sont liés à la devise ; leur période de vente,
     * saisie à l'heure du lieu, suit un changement de fuseau.
     */
    public function update(EventRequest $request, string $id): EventResource
    {
        return DB::transaction(function () use ($request, $id) {
            $event = Event::with('ticketTypes')->lockForUpdate()->findOrFail($id);
            $attributes = $request->toAttributes($event);
            $tickets = $event->ticketTypes;
            $planned = $tickets->sum('quantity');
            $capacity = array_key_exists('capacity', $attributes) ? $attributes['capacity'] : $event->capacity;

            $errors = array_filter([
                'currency' => $tickets->isNotEmpty() && $attributes['currency'] !== $event->currency
                    ? __('events.currency_locked') : null,
                'capacity' => $capacity !== null && $planned > $capacity
                    ? __('events.capacity_below_tickets', ['count' => $planned]) : null,
            ]);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            if ($attributes['timezone'] !== $event->timezone) {
                foreach ($tickets as $ticket) {
                    foreach (['sales_start_at', 'sales_end_at'] as $field) {
                        $ticket->{$field} = LocalDateTime::toUtc(LocalDateTime::fromUtc($ticket->{$field}, $event->timezone), $attributes['timezone']);
                    }
                    $ticket->save();
                }
            }

            $event->update($attributes);

            return EventResource::make($event)->withAvailability();
        });
    }

    /**
     * Seul un brouillon sans commande se supprime, et pour de bon : billets et affiche partent avec lui.
     * Un événement publié s'annulera (participants prévenus).
     */
    public function destroy(string $id): Response
    {
        $event = Event::with('design')->findOrFail($id);

        if ($event->isPublished()) {
            throw ValidationException::withMessages(['event' => __('events.delete_published')]);
        }
        if (Inventory::eventHasOrders($event->id)) {
            throw ValidationException::withMessages(['event' => __('ticketing.event_has_orders')]);
        }

        DB::transaction(function () use ($event) {
            $event->ticketTypes()->delete();
            $event->forceDelete();
        });
        $images = array_filter([$event->cover_path, $event->design?->logo_path, $event->design?->banner_path]);
        if ($images !== []) {
            Event::mediaDisk()->delete($images);
        }
        Event::mediaDisk()->deleteDirectory(EventDesign::studioDirectory($event));

        return response()->noContent();
    }

    /** Rend la page de vente publique : au moins un type de billet, et une date à venir. */
    public function publish(string $id): EventResource
    {
        $event = Event::with('ticketTypes')->findOrFail($id);

        $errors = array_filter([
            'ticket_types' => $event->ticketTypes->isEmpty() ? __('events.publish_needs_tickets') : null,
            'starts_at' => $event->starts_at->isPast() ? __('events.publish_in_past') : null,
        ]);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $event->forceFill(['status' => Event::PUBLISHED, 'published_at' => $event->published_at ?? now()])->save();

        return EventResource::make($event)->withAvailability();
    }

    /** Retire la page de vente : la vente s'arrête, les billets déjà vendus restent valables. */
    public function unpublish(string $id): EventResource
    {
        $event = Event::with('ticketTypes')->findOrFail($id);
        $event->forceFill(['status' => Event::DRAFT])->save();

        return EventResource::make($event)->withAvailability();
    }

    /** Affiche de l'événement (JPEG, PNG ou WebP, 5 Mo au plus). */
    public function cover(Request $request, string $id): EventResource
    {
        $request->validate([
            'cover' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:min_width=600,min_height=300'],
        ]);
        $event = Event::with('ticketTypes')->findOrFail($id);
        $disk = Event::mediaDisk();
        $previous = $event->cover_path;

        $event->forceFill(['cover_path' => $disk->putFile("events/{$event->organization_id}", $request->file('cover'))])->save();

        if ($previous) {
            $disk->delete($previous);
        }

        return EventResource::make($event)->withAvailability();
    }
}
