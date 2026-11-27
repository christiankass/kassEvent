<?php

namespace App\Modules\Guests\Http\Controllers;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Support\ContactBook;
use App\Modules\Events\Http\Resources\EventDesignResource;
use App\Modules\Guests\Models\Guest;
use App\Modules\Guests\Support\GuestBook;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Page de réponse de l'invité, ouverte par son lien personnel (identifiant + clé secrète), sans
 * compte : invité inconnu ou clé fausse, 404. L'événement n'a pas besoin d'être publié.
 */
final class PublicGuestController
{
    public function __construct(private readonly GuestBook $book, private readonly ContactBook $contacts) {}

    public function show(Request $request, string $id): JsonResponse
    {
        return TenantKeys::within($id, fn () => $this->payload($this->find($id, $request->query('key')))) ?? abort(404);
    }

    public function respond(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'attending' => ['required', 'boolean'],
            'companions' => ['sometimes', 'integer', 'min:0', 'max:50'],
            // « Recevoir les nouvelles de l'organisateur » : jamais coché par défaut (module Audience).
            'marketing_consent' => ['sometimes', 'boolean'],
        ]);

        return TenantKeys::within($id, function () use ($id, $data) {
            $found = $this->find($id, $data['key']);
            $firstAnswer = $found->responded_at === null;
            $guest = $this->book->respond($found, (bool) $data['attending'], (int) ($data['companions'] ?? 0));
            // L'invité entre au carnet de l'organisation (sa première réponse est comptée une fois).
            $this->contacts->remember(
                ['name' => $guest->name, 'email' => $guest->email, 'phone' => $guest->phone, 'locale' => $guest->locale],
                (bool) ($data['marketing_consent'] ?? false), Contact::RSVP, $firstAnswer ? 'invitation' : null,
            );

            return $this->payload($guest->refresh());
        }) ?? abort(404);
    }

    private function find(string $id, mixed $key): Guest
    {
        $guest = Guest::whereHas('event')->find($id);
        abort_unless($guest?->hasAccessKey($key), 404);

        return $guest;
    }

    private function payload(Guest $guest): JsonResponse
    {
        $guest->loadMissing(['event.organization', 'event.design', 'table']);
        $event = $guest->event;
        $order = $guest->order_id ? Order::find($guest->order_id) : null;

        return response()->json(['data' => [
            'guest' => [
                'name' => $guest->name,
                'companions_allowed' => $guest->companions_allowed,
                'status' => $guest->status,
                'companions_confirmed' => $guest->companions_confirmed,
                'table' => $guest->status === Guest::CONFIRMED ? $guest->table?->label() : null,
            ],
            'event' => [
                'title' => $event->title,
                'description' => $event->description,
                'starts_at_utc' => $event->starts_at->toIso8601String(),
                'timezone' => $event->timezone,
                'venue_name' => $event->venue_name,
                'venue_address' => $event->venue_address,
                'city' => $event->city,
                'cover_url' => $event->coverUrl(),
                'organization' => ['name' => $event->organization->name],
            ],
            'design' => EventDesignResource::make($event->ticketDesign()),
            'open' => ! $event->starts_at->isPast(),
            // Invitation avec QR code (page de la commande gratuite), une fois la venue confirmée.
            'invitation_url' => $order?->isPaid() ? $order->publicUrl() : null,
        ]]);
    }
}
