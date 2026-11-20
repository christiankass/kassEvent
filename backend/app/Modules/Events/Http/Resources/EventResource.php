<?php

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\LocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un événement, dates à l'heure de son lieu (« 2026-12-24T20:00 ») plus en UTC pour les calculs.
 * La page publique ne reçoit que les champs utiles aux participants.
 *
 * @mixin Event
 */
final class EventResource extends JsonResource
{
    private bool $public = false;

    public static function forPublic(Event $event): self
    {
        $resource = new self($event);
        $resource->public = true;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $tickets = $this->whenLoaded('ticketTypes', fn () => $this->ticketTypes
            ->map(fn ($ticketType) => new TicketTypeResource($ticketType, $this->timezone)));

        $public = [
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'starts_at' => LocalDateTime::fromUtc($this->starts_at, $this->timezone),
            'ends_at' => LocalDateTime::fromUtc($this->ends_at, $this->timezone),
            'starts_at_utc' => $this->starts_at->toIso8601String(),
            'timezone' => $this->timezone,
            'venue_name' => $this->venue_name,
            'venue_address' => $this->venue_address,
            'city' => $this->city,
            'currency' => $this->currency,
            'cover_url' => $this->coverUrl(),
            'ticket_types' => $tickets,
        ];

        if ($this->public) {
            return [...$public, 'organization' => ['name' => $this->organization->name]];
        }

        return [
            'id' => $this->id,
            ...$public,
            'capacity' => $this->capacity,
            'status' => $this->status,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
