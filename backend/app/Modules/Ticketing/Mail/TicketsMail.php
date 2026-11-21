<?php

namespace App\Modules\Ticketing\Mail;

use App\Modules\Events\Http\Resources\EventDesignResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventDesign;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Models\OrderItem;
use App\Modules\Ticketing\Models\Ticket;
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
    /** Code de l'exemple : contient O et U, lettres jamais tirées pour un vrai billet (voir TicketCode). */
    public const SAMPLE_CODE = 'APERCU0EXEMP';

    public function __construct(public readonly Order $order) {}

    /** Commande fictive (jamais enregistrée) pour l'aperçu et l'e-mail de test de l'atelier. */
    public static function sample(Event $event, string $holder): Order
    {
        $order = new Order(['buyer_name' => $holder, 'reference' => __('designs.sample_reference')]);
        $item = new OrderItem(['name' => $event->ticketTypes()->value('name') ?? __('designs.sample_ticket')]);
        $ticket = (new Ticket(['code' => self::SAMPLE_CODE, 'holder_name' => $holder]))->setRelation('item', $item);

        return $order->setRelation('event', $event)->setRelation('tickets', collect([$ticket]));
    }

    public function envelope(): Envelope
    {
        $design = $this->design();
        $key = $design->isInvitation() ? 'ticketing.mail.subject_invitation' : 'ticketing.mail.subject';

        return new Envelope(subject: __($key, ['event' => $this->order->event->title]));
    }

    public function content(): Content
    {
        $event = $this->order->event;
        $design = $this->design();

        return new Content(
            view: 'mail.tickets',
            with: [
                'order' => $this->order,
                'event' => $event,
                'design' => $design,
                'look' => EventDesignResource::make($design)->resolve(),
                'images' => array_filter([
                    'logo' => $this->image($design->logo_path, 'logo'),
                    'banner' => $this->image($design->bannerPath(), 'banner'),
                ]),
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

    private function design(): EventDesign
    {
        return $this->order->event->ticketDesign();
    }

    /** @return array{data: string, mime: string, name: string}|null image intégrée à l'e-mail (les liens externes sont souvent bloqués) */
    private function image(?string $path, string $name): ?array
    {
        $disk = Event::mediaDisk();
        if (! $path || ! $disk->exists($path)) {
            return null;
        }
        $mime = $disk->mimeType($path) ?: 'image/png';

        return ['data' => $disk->get($path), 'mime' => $mime, 'name' => $name.'.'.pathinfo($path, PATHINFO_EXTENSION)];
    }
}
