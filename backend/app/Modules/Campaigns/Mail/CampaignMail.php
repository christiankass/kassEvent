<?php

namespace App\Modules\Campaigns\Mail;

use App\Modules\Audience\Models\Contact;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignMessage;
use App\Modules\Campaigns\Support\CampaignLinks;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * E-mail d'une campagne : le texte de l'organisateur (texte simple, jamais de HTML), l'événement mis
 * en avant avec son bouton, et le lien de désinscription. Sans $message (essai ou aperçu) : ni
 * image de suivi, ni lien de désinscription réel.
 */
final class CampaignMail extends Mailable
{
    public function __construct(
        public readonly Campaign $campaign,
        public readonly ?Contact $contact = null,
        public readonly ?CampaignMessage $message = null,
        public readonly bool $test = false,
        public readonly ?string $sampleName = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->campaign->subject;

        return new Envelope(subject: $this->test ? __('campaigns.mail.test', ['subject' => $subject]) : $subject);
    }

    /** Vrai envoi à un contact (pas un essai ni un aperçu) : suivi et désinscription réels. */
    private function live(): bool
    {
        return $this->contact !== null && $this->message !== null;
    }

    public function headers(): Headers
    {
        if (! $this->live()) {
            return new Headers;
        }

        // « Se désinscrire » de la messagerie en un clic (RFC 8058), sans ouvrir de page.
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->contact->apiUnsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $campaign = $this->campaign;
        $event = $campaign->event;
        // {nom} : le nom du contact ; dans un essai, celui de la personne connectée ; dans l'aperçu, un exemple.
        $name = $this->contact?->name ?? $this->sampleName ?? __('campaigns.mail.sample_name');
        $body = str_replace(['{nom}', '{name}'], $name, $campaign->body);

        return new Content(view: 'mail.campaign', with: [
            'subject' => $campaign->subject,
            'organization' => $campaign->organization->name,
            'paragraphs' => array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $body) ?: []), fn ($p) => $p !== '')),
            'event' => $event,
            'when' => $event?->starts_at->timezone($event->timezone)->locale(app()->getLocale())->translatedFormat('l j F Y, H:i'),
            'button' => $event ? ($campaign->button_label ?: __('campaigns.mail.button')) : null,
            'url' => $event ? $this->buttonUrl() : null,
            'unsubscribe' => $this->live() ? $this->contact->unsubscribeUrl() : null,
            'pixel' => $this->live() ? route('api.v1.public.campaigns.open', ['campaign' => $campaign->id, 'message' => $this->message->id]) : null,
        ]);
    }

    private function buttonUrl(): string
    {
        return $this->live()
            ? route('api.v1.public.campaigns.click', ['campaign' => $this->campaign->id, 'message' => $this->message->id])
            : CampaignLinks::eventUrl($this->campaign);
    }
}
