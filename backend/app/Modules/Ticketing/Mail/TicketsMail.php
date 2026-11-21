<?php

namespace App\Modules\Ticketing\Mail;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventDesign;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Support\QrCode;
use App\Modules\Ticketing\Support\TicketCode;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * E-mail des billets : un billet par place, aux couleurs choisies par l'organisateur, avec son QR code
 * (image PNG intégrée, toujours noir sur blanc) et le lien de la commande.
 */
final class TicketsMail extends Mailable
{
    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __($this->key('subject'), ['event' => $this->order->event->title]));
    }

    public function content(): Content
    {
        $event = $this->order->event;
        $design = $event->ticketDesign();

        return new Content(
            view: 'mail.tickets',
            with: [
                'order' => $this->order,
                'event' => $event,
                'design' => $design,
                'colors' => $design->colors(),
                'subject' => $this->envelope()->subject,
                'key' => $this->key(...),
                'images' => array_filter(['logo' => $design->logo_path, 'banner' => $design->bannerPath()]),
                'when' => $event->starts_at->timezone($event->timezone)->locale(app()->getLocale())->translatedFormat('l j F Y, H:i'),
                'tickets' => $this->order->tickets->map(fn ($ticket) => [
                    'code' => TicketCode::format($ticket->code),
                    'type' => $ticket->item->name,
                    'holder' => $ticket->holder_name,
                    'qr' => QrCode::png($ticket->code),
                    'file' => "billet-{$ticket->code}.png",
                ]),
                // Commande d'exemple (atelier) : pas de page de commande à ouvrir.
                'url' => $this->order->exists ? $this->order->publicUrl() : null,
            ],
        );
    }

    /**
     * Image du disque des médias intégrée à l'e-mail (les liens externes sont souvent bloqués).
     * Rendu sans envoi (aperçu de l'atelier) : simple lien vers l'image.
     */
    public static function imageSource(?object $message, string $path): ?string
    {
        if (! $message) {
            return EventDesign::url($path);
        }
        $data = Event::mediaDisk()->get($path);

        return $data === null ? null : $message->embedData($data, basename($path), match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        });
    }

    /** Clé de traduction, au vocabulaire de l'invitation quand l'organisateur l'a choisi. */
    private function key(string $name): string
    {
        return 'ticketing.mail.'.$name.($this->order->event->ticketDesign()->isInvitation() ? '_invitation' : '');
    }
}
