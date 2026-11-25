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

/**
 * Envoie les billets d'une commande sur WhatsApp ou Messenger (file « notifications »). Chaque
 * message envoyé est enregistré aussitôt (delivery->messages) et sauté aux essais suivants. Un job
 * s'arrête après quelques messages ou 40 secondes, bien sous le délai du worker, même pour 50 billets :
 * le suivant reprend où il s'est arrêté. Un job d'un envoi remplacé entre-temps (autre tirage) ne fait rien.
 */
final class DeliverTickets implements ShouldQueue
{
    use Queueable;

    /** Temps d'envoi par job ; un message coûte au plus une image et deux appels à Meta (15 s chacun). */
    private const BUDGET_SECONDS = 40;

    public int $tries = 5;

    /** Sous le retry_after de la file (90 s) : un job jamais repris en double pendant qu'il tourne. */
    public int $timeout = 80;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly string $organizationId, public readonly string $deliveryId, public readonly string $attempt)
    {
        $this->onQueue('notifications');
    }

    public function handle(TenantContext $context, ChannelRegistry $channels): void
    {
        $context->run($this->organizationId, function () use ($channels) {
            $delivery = Delivery::with('order')->find($this->deliveryId);
            if ($delivery?->status !== Delivery::QUEUED || $delivery->attempt !== $this->attempt) {
                return; // nouvel envoi demandé entre-temps, ou déjà traité
            }

            $channel = $channels->for($delivery->channel);
            $left = (int) config('messaging.messages_per_job');
            $deadline = microtime(true) + self::BUDGET_SECONDS;
            try {
                foreach ($channel->parts($delivery, new TicketMessages($delivery->order)) as $key => $send) {
                    if (isset($delivery->messages[$key])) {
                        continue;
                    }
                    if ($left-- === 0 || microtime(true) > $deadline) {
                        self::dispatch($this->organizationId, $this->deliveryId, $this->attempt);

                        return;
                    }
                    $delivery->messages = [...($delivery->messages ?? []), $key => $send()];
                    $delivery->save();
                }
            } catch (DeliveryFailed $e) {
                $this->finish(Delivery::FAILED, $e->reason);

                return;
            }

            $this->finish($channel->confirmsAtOnce() ? Delivery::DELIVERED : Delivery::SENT);
        });
    }

    public function failed(Throwable $e): void
    {
        app(TenantContext::class)->run($this->organizationId, fn () => $this->finish(Delivery::FAILED, 'provider_unavailable'));
    }

    /** Seulement l'envoi de ce tirage, encore en cours : un accusé d'échec de Meta n'est pas écrasé. */
    private function finish(string $status, ?string $failureCode = null): void
    {
        Delivery::whereKey($this->deliveryId)->where('attempt', $this->attempt)->where('status', Delivery::QUEUED)->update([
            'status' => $status,
            'failure_code' => $failureCode,
            'updated_at' => now(),
        ]);
    }
}
