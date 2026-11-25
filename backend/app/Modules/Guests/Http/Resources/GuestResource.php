<?php

namespace App\Modules\Guests\Http\Resources;

use App\Modules\Guests\Models\Guest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Invité vu par l'organisation. Le lien personnel de réponse (qui ouvre l'invitation de l'invité)
 * n'est donné qu'à qui peut gérer l'événement : c'est lui qui l'envoie.
 *
 * @mixin Guest
 */
final class GuestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'group_name' => $this->group_name,
            'companions_allowed' => $this->companions_allowed,
            'status' => $this->status,
            'companions_confirmed' => $this->companions_confirmed,
            'party' => $this->party(),
            'table_id' => $this->table_id,
            'invited_at' => $this->invited_at?->toIso8601String(),
            'reminded_at' => $this->reminded_at?->toIso8601String(),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'can_remind' => $this->canBeReminded(),
            'rsvp_url' => $request->user()?->can('events.manage') ? $this->rsvpUrl() : null,
        ];
    }
}
