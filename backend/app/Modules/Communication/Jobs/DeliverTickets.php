<?php

namespace App\Modules\Communication\Jobs;

use App\Modules\Communication\Channels\ChannelRegistry;
use App\Modules\Communication\Channels\DeliveryFailed;
use App\Modules\Communication\Models\Delivery;
use App\Modules\Communication\Support\TicketMessages;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Envoie les billets d'une commande sur WhatsApp ou Messenger (file « notifications »). */
final class DeliverTickets implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly string $organizationId, public readonly string $deliveryId)
    {
        $this->onQueue('notifications');
    }

    public function handle(TenantContext $context, ChannelRegistry $channels): void
    {
        $context->run($this->organizationId, function () use ($channels) {
            $delivery = Delivery::with('order')->find($this->deliveryId);
            if ($delivery?->status !== Delivery::QUEUED) {
                return; // nouvel envoi demandé entre-temps, ou déjà traité
            }

            try {
                $channels->for($delivery->channel)->send($delivery, new TicketMessages($delivery->order));
            } catch (DeliveryFailed $e) {
                $this->finish(Delivery::FAILED, $e->reason);

                return;
            }

            // Mode simulé : aucun accusé de Meta ne viendra, l'envoi est tenu pour remis.
            $this->finish(ChannelRegistry::mode($delivery->channel) === 'simulated' ? Delivery::DELIVERED : Delivery::SENT);
        });
    }

    public function failed(Throwable $e): void
    {
        app(TenantContext::class)->run($this->organizationId, fn () => $this->finish(Delivery::FAILED, 'provider_unavailable'));
    }

    /** Seulement depuis « queued » : un accusé de Meta arrivé pendant l'envoi (remis, lu) n'est pas écrasé. */
    private function finish(string $status, ?string $failureCode = null): void
    {
        Delivery::whereKey($this->deliveryId)->where('status', Delivery::QUEUED)->update([
            'status' => $status,
            'failure_code' => $failureCode,
            'sent_at' => $status === Delivery::FAILED ? null : now(),
            'updated_at' => now(),
        ]);
    }
}
