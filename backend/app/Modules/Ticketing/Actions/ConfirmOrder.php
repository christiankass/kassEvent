<?php

namespace App\Modules\Ticketing\Actions;

use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Ticketing\Jobs\SendTickets;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Support\TicketCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Commande payée (ou gratuite) : émet un billet par place et l'envoie par e-mail.
 * Idempotent : une commande déjà payée n'émet pas de seconds billets.
 */
final class ConfirmOrder
{
    public function handle(string $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $order = Order::with('items')->lockForUpdate()->findOrFail($orderId);

            if ($order->isPaid()) {
                return;
            }

            // Un paiement validé après l'expiration de la réservation est honoré : l'acheteur a payé.
            // Les réservations sont prolongées pendant chaque paiement, ce cas reste exceptionnel.
            if ($order->isExpired()) {
                Log::warning('Commande payée après expiration de sa réservation', ['order' => $order->id]);
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
        $order = Order::find($event->payment->order_id);

        if ($order?->isPaid()) {
            // Deuxième paiement réussi pour une commande déjà payée (carte puis mobile money, par exemple).
            Log::warning('Paiement reçu pour une commande déjà payée : à rembourser', ['payment' => $event->payment->id]);

            return;
        }

        $this->handle($event->payment->order_id);
    }
}
