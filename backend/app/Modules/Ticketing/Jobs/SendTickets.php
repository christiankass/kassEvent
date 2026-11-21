<?php

namespace App\Modules\Ticketing\Jobs;

use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Ticketing\Mail\TicketsMail;
use App\Modules\Ticketing\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/** Envoie les billets d'une commande payée à l'acheteur, dans sa langue. */
final class SendTickets implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly string $organizationId, public readonly string $orderId)
    {
        $this->onQueue('notifications');
    }

    public function handle(TenantContext $context): void
    {
        $context->run($this->organizationId, function () {
            $order = Order::with(['event.organization', 'event.design', 'tickets.item'])->findOrFail($this->orderId);

            Mail::to($order->buyer_email, $order->buyer_name)
                ->locale($order->locale)
                ->send(new TicketsMail($order));
        });
    }
}
