<?php

namespace App\Modules\Events\Http\Resources;

use App\Modules\Communication\Channels\ChannelRegistry;
use App\Modules\Communication\Models\Delivery;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\LocalDateTime;
use App\Modules\Ticketing\Support\Inventory;
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

    /** @var array<string, int> places prises par type de billet (voir Inventory::taken) */
    private array $taken = [];

    public static function forPublic(Event $event): self
    {
        $resource = new self($event);
        $resource->public = true;

        return $resource;
    }

    /**
     * Ajoute les places restantes de chaque billet. À appeler dans le contexte de l'organisation de l'événement.
     *
     * @param  array<string, int>|null  $taken  déjà calculées (liste d'événements), sinon calculées ici
     */
    public function withAvailability(?array $taken = null): self
    {
        $this->taken = $taken ?? Inventory::taken($this->ticketTypes->modelKeys());

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $tickets = $this->whenLoaded('ticketTypes', fn () => $this->ticketTypes
            ->map(fn ($ticketType) => new TicketTypeResource($ticketType, $this->timezone, $this->taken[$ticketType->id] ?? 0)));

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
            return [
                ...$public,
                'organization' => ['name' => $this->organization->name],
                // Canaux proposés à l'achat en plus de l'e-mail (Messenger se demande après, depuis la commande).
                'delivery' => ['whatsapp' => ChannelRegistry::enabled(Delivery::WHATSAPP)],
            ];
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
