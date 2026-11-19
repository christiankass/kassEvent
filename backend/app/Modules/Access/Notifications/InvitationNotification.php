<?php

namespace App\Modules\Access\Notifications;

use App\Modules\Access\Models\Invitation;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail d'invitation : le lien ouvre la page du site, qui affiche l'invitation et permet de l'accepter. */
final class InvitationNotification extends Notification
{
    public function __construct(
        private readonly Invitation $invitation,
        private readonly Organization $organization,
        private readonly string $token,
        private readonly string $inviterName,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('app.frontend_url').'/invitation?'.http_build_query([
            'organisation' => $this->organization->id,
            'jeton' => $this->token,
        ]);
        $values = [
            'inviter' => $this->inviterName,
            'organization' => $this->organization->name,
            'role' => __('access.roles.'.$this->invitation->role),
        ];

        return (new MailMessage)
            ->subject(__('access.mail.subject', $values))
            ->greeting(__('access.mail.greeting'))
            ->line(__('access.mail.intro', $values))
            ->action(__('access.mail.action'), $url)
            ->line(__('access.mail.expires', ['date' => $this->invitation->expires_at->translatedFormat('j F Y')]))
            ->line(__('access.mail.ignore'));
    }
}
