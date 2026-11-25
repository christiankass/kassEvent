<?php

namespace App\Modules\Ticketing\Http\Resources;

use App\Modules\Communication\Channels\ChannelRegistry;
use App\Modules\Communication\Models\Delivery;
use App\Modules\Events\Http\Resources\EventDesignResource;
use App\Modules\Payments\Support\Phone;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Support\QrCode;
use App\Modules\Ticketing\Support\TicketCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commande vue par son acheteur (lien personnel) : statut, paiement en cours, billets avec QR code
 * une fois payée. Le numéro de téléphone n'est renvoyé que masqué.
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payment = $this->latestPayment;
        $event = $this->event;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->displayStatus(),
            'currency' => $this->currency,
            'total' => $this->total,
            'buyer_name' => $this->buyer_name,
            'buyer_email' => $this->buyer_email,
            'expires_at' => $this->expires_at->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'invitation' => $this->usesInvitationWording(),
            'event' => [
                'slug' => $event->slug,
                'title' => $event->title,
                'starts_at_utc' => $event->starts_at->toIso8601String(),
                'timezone' => $event->timezone,
                'venue_name' => $event->venue_name,
                'city' => $event->city,
                'organization' => ['name' => $event->organization->name],
            ],
            // Apparence des billets choisie par l'organisateur (atelier), sinon celle de kassEvent.
            'design' => $this->isPaid() ? EventDesignResource::make($event->ticketDesign()) : null,
            'items' => $this->items->map(fn ($item) => [
                'name' => $item->name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
            ]),
            'payment' => $payment ? [
                'method' => $payment->method,
                'status' => $payment->status,
                'operator' => $payment->operator,
                'phone' => Phone::mask($payment->phone),
                'failure_code' => $payment->failure_code,
                // Page de paiement externe à ouvrir (carte), tant que le paiement attend.
                'redirect_url' => $payment->isPending() ? $payment->redirect_url : null,
                'simulated' => $payment->provider === 'simulated',
            ] : null,
            // Envois sur WhatsApp et Messenger, et canaux que l'acheteur peut demander.
            'deliveries' => $this->deliveries->map(fn ($delivery) => [
                'channel' => $delivery->channel,
                'status' => $delivery->status,
                'recipient' => $delivery->maskedRecipient(),
                'failure_code' => $delivery->failure_code,
            ]),
            'channels' => [
                'whatsapp' => ChannelRegistry::enabled(Delivery::WHATSAPP),
                'messenger' => ChannelRegistry::enabled(Delivery::MESSENGER),
                'simulated' => ChannelRegistry::simulationEnabled(),
            ],
            'tickets' => $this->isPaid() ? $this->tickets->map(fn ($ticket) => [
                'code' => TicketCode::format($ticket->code),
                'name' => $ticket->label(),
                'holder_name' => $ticket->holder_name,
                'companions' => (int) $ticket->companions,
                'seat' => $ticket->seat,
                'qr' => 'data:image/svg+xml;base64,'.base64_encode(QrCode::svg($ticket->code)),
            ]) : [],
        ];
    }
}
