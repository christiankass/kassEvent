<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Http\Resources\EventResource;
use App\Modules\Events\Models\Event;
use App\Modules\Tenancy\Scopes\OrganizationScope;
use App\Modules\Tenancy\Support\TenantContext;

/** Page de vente publique d'un événement publié, sans compte. Brouillon ou inconnu : 404. */
final class PublicEventController
{
    public function show(string $slug): EventResource
    {
        $event = Event::publiclyVisible()
            ->where('slug', $slug)
            ->with(['organization', 'ticketTypes' => fn ($query) => $query->withoutGlobalScope(OrganizationScope::class)])
            ->firstOrFail();

        // Places restantes : les commandes ne se lisent que dans le contexte de l'organisation.
        return app(TenantContext::class)->run($event->organization_id, fn () => EventResource::forPublic($event)->withAvailability());
    }
}
