<?php

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Support\CampaignSender;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Un lot d'e-mails d'une campagne. Relancé après une panne, il ne renvoie pas ceux déjà partis. */
final class SendCampaignMessages implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** @param  list<string>  $messageIds */
    public function __construct(public readonly string $organizationId, public readonly string $campaignId, public readonly array $messageIds)
    {
        $this->onQueue('notifications');
    }

    public function handle(TenantContext $context, CampaignSender $sender): void
    {
        $context->run($this->organizationId, function () use ($sender) {
            $campaign = Campaign::find($this->campaignId);
            if ($campaign?->status === Campaign::SENDING) {
                $sender->deliver($campaign, $this->messageIds);
            }
        });
    }
}
