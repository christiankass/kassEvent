<?php

namespace App\Modules\Campaigns\Console;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Support\CampaignSender;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/** Lance les campagnes programmées dont l'heure est venue (planifié chaque minute). */
final class DispatchCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch';

    protected $description = 'Lance les campagnes e-mail programmées arrivées à leur heure';

    public function handle(TenantContext $context, CampaignSender $sender): int
    {
        $context->eachOrganization(fn () => Campaign::where('status', Campaign::SCHEDULED)->where('scheduled_at', '<=', now())
            ->get()->each(fn (Campaign $campaign) => $sender->start($campaign)));

        return self::SUCCESS;
    }
}
