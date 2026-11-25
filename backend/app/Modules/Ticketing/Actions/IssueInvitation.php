<?php

namespace App\Modules\Ticketing\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Events\TicketsIssued;
use App\Modules\Ticketing\Jobs\SendTickets;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Models\Ticket;
use App\Modules\Ticketing\Support\TicketCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invitation nominative d'un invité (module Guests) : une commande gratuite, hors vente, avec un
 * seul QR code au nom de l'invité, ses accompagnants et sa table. À appeler dans le contexte de
 * l'organisation de l'événement.
 *
 * L'invité qui se désiste fait annuler sa commande (son QR code n'ouvre plus la porte) ; s'il
 * confirme de nouveau, la même commande et le même QR code redeviennent valables.
 */
final class IssueInvitation
{
    /**
     * Émet (ou rend valable de nouveau) l'invitation, et l'envoie par e-mail et sur WhatsApp
     * (via TicketsIssued, si un envoi est prévu). Rend la commande.
     *
     * @param  array{name: string, email: string|null, locale: string, companions: int, seat: string|null}  $guest
     */
    public function issue(Event $event, array $guest, ?string $orderId): Order
    {
        return DB::transaction(function () use ($event, $guest, $orderId) {
            $order = $orderId ? Order::lockForUpdate()->find($orderId) : null;
            $order ??= $this->create($event, $guest);
            $wasValid = $order->isPaid();

            $order->forceFill([
                'status' => Order::PAID,
                'paid_at' => $order->paid_at ?? now(),
                'buyer_name' => $guest['name'],
                'buyer_email' => $guest['email'],
                'locale' => $guest['locale'],
            ])->save();

            $ticket = $order->tickets()->first() ?? $order->tickets()->create([
                'order_item_id' => $order->items()->value('id'),
                'event_id' => $order->event_id,
                'code' => TicketCode::generate(),
                'holder_name' => $guest['name'],
            ]);
            $ticket->forceFill(['holder_name' => $guest['name'], 'companions' => $guest['companions'], 'seat' => $guest['seat']])->save();

            if (! $wasValid) {
                SendTickets::dispatch($order->organization_id, $order->id)->afterCommit();
                event(new TicketsIssued($order->organization_id, $order->id));
            }

            return $order;
        });
    }

    /** L'invité ne vient plus : son QR code est refusé au scan. */
    public function cancel(string $orderId): void
    {
        Order::whereKey($orderId)->where('source', Order::INVITATION)->update(['status' => Order::CANCELLED, 'updated_at' => now()]);
    }

    /**
     * Met à jour ce qui est imprimé sur une invitation déjà émise (nom, accompagnants, table),
     * sans la renvoyer. Sans effet si l'invitation n'existe pas.
     *
     * @param  array{name?: string, email?: string|null, companions?: int, seat?: string|null}  $changes
     */
    public function update(string $orderId, array $changes): void
    {
        $order = array_filter(['buyer_name' => $changes['name'] ?? null]);
        if (array_key_exists('email', $changes)) {
            $order['buyer_email'] = $changes['email'];
        }
        if ($order !== []) {
            Order::whereKey($orderId)->where('source', Order::INVITATION)->update($order);
        }

        $ticket = array_filter(['holder_name' => $changes['name'] ?? null, 'companions' => $changes['companions'] ?? null], fn ($v) => $v !== null);
        if (array_key_exists('seat', $changes)) {
            $ticket['seat'] = $changes['seat'];
        }
        if ($ticket !== []) {
            Ticket::where('order_id', $orderId)->update($ticket);
        }
    }

    /** @param  array{name: string, email: string|null, locale: string}  $guest */
    private function create(Event $event, array $guest): Order
    {
        $order = new Order([
            'reference' => 'KE'.TicketCode::generate(10),
            'currency' => $event->currency,
            'total' => 0,
            'buyer_name' => $guest['name'],
            'buyer_email' => $guest['email'],
            'locale' => $guest['locale'],
            'access_key' => Str::random(40),
            'expires_at' => now(),
        ]);
        $order->forceFill(['source' => Order::INVITATION]);
        $order->event()->associate($event);
        $order->save();
        TenantKeys::remember($order->id, $order->organization_id);

        $order->items()->create(['ticket_type_id' => null, 'name' => __('guests.invitation', [], $guest['locale']), 'quantity' => 1, 'unit_price' => 0]);

        return $order;
    }
}
