<?php

namespace App\Modules\Campaigns\Console;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Support\CampaignSender;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Lance les campagnes programmées dont l'heure est venue (planifié chaque minute). */
final class DispatchCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch';

    protected $description = 'Lance les campagnes e-mail programmées arrivées à leur heure';

    public function handle(TenantContext $context, CampaignSender $sender): int
    {
        DB::table('organizations')->whereNull('deleted_at')->orderBy('id')->pluck('id')
            ->each(function (string $organizationId) use ($context, $sender) {
                try {
                    $context->run($organizationId, fn () => Campaign::where('status', Campaign::SCHEDULED)
                        ->where('scheduled_at', '<=', now())->get()->each(fn (Campaign $campaign) => $sender->start($campaign)));
                } catch (Throwable $e) {
                    report($e); // une organisation en erreur n'empêche pas les suivantes
                }
            });

        return self::SUCCESS;
    }
}
