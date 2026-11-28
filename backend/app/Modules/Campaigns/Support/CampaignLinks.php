<?php

namespace App\Modules\Campaigns\Support;

use App\Modules\Campaigns\Models\Campaign;

final class CampaignLinks
{
    /**
     * Page publique de l'événement, marquée de la campagne : la page la retient et la joint à la
     * commande (statistiques « e-mail », ventes rapportées par la campagne).
     */
    public static function eventUrl(Campaign $campaign): string
    {
        $event = $campaign->event;
        if ($event === null || ! $event->isPublished()) {
            return (string) config('app.frontend_url');
        }

        return $event->publicUrl().'?'.http_build_query(['src' => 'email', 'cmp' => $campaign->id]);
    }
}
