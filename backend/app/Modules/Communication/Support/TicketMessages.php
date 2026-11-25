<?php

namespace App\Modules\Communication\Support;

use App\Modules\Events\Models\EventDesign;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Models\Ticket;
use App\Modules\Ticketing\Support\ClassicTicketImage;
use App\Modules\Ticketing\Support\TicketCode;
use App\Modules\Ticketing\Support\TicketImage;
use Illuminate\Support\Traits\Localizable;

/**
 * Contenu des messages d'une commande payée, dans la langue de l'acheteur : un billet en image
 * par place (billet du studio, sinon billet simple de l'atelier dessiné en image), et les textes
 * au vocabulaire du billet ou de l'invitation.
 */
final class TicketMessages
{
    use Localizable;

    public readonly EventDesign $design;

    public readonly string $when;

    public function __construct(public readonly Order $order)
    {
        $order->loadMissing(['event.organization', 'event.design', 'tickets.item']);
        $event = $order->event;
        $this->design = $event->ticketDesign();
        $this->when = $event->starts_at->timezone($event->timezone)->locale($order->locale)->translatedFormat('l j F Y, H:i');
    }

    public function isInvitation(): bool
    {
        return $this->order->usesInvitationWording();
    }

    /** @return iterable<Ticket> */
    public function tickets(): iterable
    {
        return $this->order->tickets;
    }

    /** Dans la langue de l'acheteur (titre par défaut du billet), pas dans celle du worker. */
    public function image(Ticket $ticket): string
    {
        return TicketImage::forTicket($this->design, $ticket)
            ?? $this->withLocale($this->order->locale, fn () => ClassicTicketImage::render($this->design, $ticket, $this->when));
    }

    public function code(Ticket $ticket): string
    {
        return TicketCode::format($ticket->code);
    }

    /** Message d'accueil (Messenger), au pluriel selon le nombre de billets. */
    public function intro(): string
    {
        return trans_choice($this->key('intro'), $this->order->tickets->count(), [
            'name' => $this->order->buyer_name,
            'event' => $this->order->event->title,
            'when' => $this->when,
            'count' => $this->order->tickets->count(),
        ], $this->order->locale);
    }

    public function caption(Ticket $ticket): string
    {
        return __($this->key('caption'), ['holder' => $ticket->holder_name, 'type' => $ticket->label(), 'code' => $this->code($ticket)], $this->order->locale);
    }

    public function text(string $name): string
    {
        return __($this->key($name), [], $this->order->locale);
    }

    /** Page de la commande (billets en ligne, impression), lien personnel de l'acheteur. */
    public function url(): string
    {
        return $this->order->publicUrl();
    }

    private function key(string $name): string
    {
        return 'communication.'.$name.($this->isInvitation() ? '_invitation' : '');
    }
}
