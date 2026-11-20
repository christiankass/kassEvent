<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Http\Resources\EventResource;
use App\Modules\Events\Models\Event;
use App\Modules\Tenancy\Scopes\OrganizationScope;

/** Page de vente publique d'un événement publié, sans compte. Brouillon ou inconnu : 404. */
final class PublicEventController
{
    public function show(string $slug): EventResource
    {
        $event = Event::publiclyVisible()
            ->where('slug', $slug)
            ->with(['organization', 'ticketTypes' => fn ($query) => $query->withoutGlobalScope(OrganizationScope::class)])
            ->firstOrFail();

        return EventResource::forPublic($event);
    }
}
