<?php

namespace App\Modules\Ticketing\Actions;

use App\Modules\Events\Models\TicketType;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Ticketing\Jobs\SendTickets;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Support\Inventory;
use App\Modules\Ticketing\Support\TicketCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Commande payée (ou gratuite) : émet un billet par place et l'envoie par e-mail.
 * Idempotent : une commande déjà réglée n'émet pas de seconds billets.
 */
final class ConfirmOrder
{
    /** @param  string|null  $paymentId  paiement qui a réglé la commande (null : commande gratuite) */
    public function handle(string $orderId, ?string $paymentId = null): void
    {
        DB::transaction(function () use ($orderId, $paymentId) {
            $order = Order::with('items')->lockForUpdate()->findOrFail($orderId);

            if ($order->status !== Order::PENDING) {
                // Second paiement réussi pour une commande déjà réglée (carte puis mobile money, par exemple).
                Log::warning('Paiement reçu pour une commande déjà réglée : à rembourser', ['order' => $order->id, 'payment' => $paymentId]);

                return;
            }

            // Payée après l'expiration de sa réservation : ses places ont pu être vendues entre-temps.
            if ($order->isExpired() && ! $this->seatsStillAvailable($order)) {
                $order->forceFill(['status' => Order::REFUND_DUE, 'paid_at' => now()])->save();
                Log::error('Commande payée après expiration, places vendues entre-temps : à rembourser', ['order' => $order->id, 'payment' => $paymentId]);

                return;
            }

            $order->forceFill(['status' => Order::PAID, 'paid_at' => now()])->save();

            foreach ($order->items as $item) {
                for ($i = 0; $i < $item->quantity; $i++) {
                    $order->tickets()->create([
                        'order_item_id' => $item->id,
                        'event_id' => $order->event_id,
                        'code' => TicketCode::generate(),
                        'holder_name' => $order->buyer_name,
                    ]);
                }
            }

            SendTickets::dispatch($order->organization_id, $order->id)->afterCommit();
        });
    }

    /** Écoute les paiements réussis (module Payments). */
    public function onPaymentSucceeded(PaymentSucceeded $event): void
    {
        $this->handle($event->payment->order_id, $event->payment->id);
    }

    /** Sous le même verrou que la prise de commande ; la commande expirée n'occupe déjà plus de places. */
    private function seatsStillAvailable(Order $order): bool
    {
        $ticketTypes = TicketType::whereIn('id', $order->items->pluck('ticket_type_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $taken = Inventory::taken($ticketTypes->keys());

        return $order->items->every(fn ($item) => $item->quantity <= $ticketTypes[$item->ticket_type_id]->quantity - ($taken[$item->ticket_type_id] ?? 0));
    }
}
