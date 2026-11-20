<?php

namespace App\Modules\Ticketing\Mail;

use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Support\QrCode;
use App\Modules\Ticketing\Support\TicketCode;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** E-mail des billets : un billet par place, avec son QR code (image PNG intégrée) et le lien de la commande. */
final class TicketsMail extends Mailable
{
    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('ticketing.mail.subject', ['event' => $this->order->event->title]));
    }

    public function content(): Content
    {
        $event = $this->order->event;

        return new Content(
            view: 'mail.tickets',
            with: [
                'order' => $this->order,
                'event' => $event,
                'when' => $event->starts_at->timezone($event->timezone)->locale(app()->getLocale())->translatedFormat('l j F Y, H:i'),
                'tickets' => $this->order->tickets->map(fn ($ticket) => [
                    'code' => TicketCode::format($ticket->code),
                    'type' => $ticket->item->name,
                    'holder' => $ticket->holder_name,
                    'qr' => QrCode::png($ticket->code),
                    'file' => "billet-{$ticket->code}.png",
                ]),
                'url' => $this->order->publicUrl(),
            ],
        );
    }
}
