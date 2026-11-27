<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Models\EventVisit;
use App\Modules\Analytics\Support\Referral;
use App\Modules\Events\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Visite de la page publique, signalée par le navigateur du visiteur (une fois par session et par
 * événement) avec la source lue dans le lien partagé. Événement non publié ou inconnu : 404.
 */
final class PublicVisitController
{
    public function __invoke(Request $request, string $slug): Response
    {
        $event = Event::publiclyVisible()->where('slug', $slug)->firstOrFail();
        EventVisit::record($event, Referral::normalize($request->input('source')));

        return response()->noContent();
    }
}
