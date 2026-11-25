<?php

namespace App\Modules\Guests\Mail;

use App\Modules\Guests\Models\Guest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Invitation par e-mail : aux couleurs de l'événement, avec le lien personnel où l'invité répond.
 * Le QR code part ensuite, une fois sa venue confirmée.
 */
final class GuestInvitationMail extends Mailable
{
    public function __construct(public readonly Guest $guest) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('guests.mail.subject', ['event' => $this->guest->event->title]));
    }

    public function content(): Content
    {
        $event = $this->guest->event;
        $design = $event->ticketDesign();

        return new Content(view: 'mail.guest-invitation', with: [
            'guest' => $this->guest,
            'event' => $event,
            'design' => $design,
            'colors' => $design->colors(),
            'subject' => $this->envelope()->subject,
            'when' => $event->starts_at->timezone($event->timezone)->locale(app()->getLocale())->translatedFormat('l j F Y, H:i'),
            'url' => $this->guest->rsvpUrl(),
        ]);
    }
}
