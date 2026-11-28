<?php

namespace App\Modules\Campaigns\Http\Controllers;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignMessage;
use App\Modules\Campaigns\Support\CampaignLinks;
use App\Modules\Campaigns\Support\CampaignSender;
use App\Modules\Tenancy\Support\TenantKeys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Suivi d'une campagne, sans compte : ouverture (image invisible) et clic sur le bouton. Identifiants
 * aléatoires de la campagne et de l'envoi ; le clic ne mène qu'à la page de l'événement de la campagne.
 */
final class PublicCampaignTrackingController
{
    /** GIF transparent de 1 × 1 pixel. */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(private readonly CampaignSender $sender) {}

    public function open(string $campaign, string $message): Response
    {
        TenantKeys::within($campaign, function () use ($campaign, $message) {
            $found = $this->find($campaign, $message);
            if ($found !== null) {
                $this->sender->opened($found);
            }
        });

        // Toujours la même image, même pour un lien inconnu : rien à apprendre en la demandant.
        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function click(string $campaign, string $message): RedirectResponse
    {
        $url = TenantKeys::within($campaign, function () use ($campaign, $message) {
            $found = $this->find($campaign, $message);
            if ($found === null) {
                return null;
            }
            $this->sender->clicked($found);

            return CampaignLinks::eventUrl($found->campaign);
        });

        return redirect()->away($url ?? (string) config('app.frontend_url'));
    }

    private function find(string $campaign, string $message): ?CampaignMessage
    {
        return CampaignMessage::with('campaign.event')->where('campaign_id', $campaign)
            ->whereHas('campaign', fn ($query) => $query->whereIn('status', [Campaign::SENDING, Campaign::SENT]))
            ->find($message);
    }
}
