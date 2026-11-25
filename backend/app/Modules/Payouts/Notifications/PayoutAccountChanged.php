<?php

namespace App\Modules\Payouts\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Alerte de sécurité : le compte où l'organisation reçoit son argent vient de changer. Envoyée à
 * tous les responsables des versements, pour qu'un changement frauduleux soit vu avant le premier
 * reversement. Ne contient que des valeurs déjà masquées (aucune donnée lue hors contexte).
 */
final class PayoutAccountChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /** Heure affichée : celle de Kinshasa, où sont les organisateurs aujourd'hui. */
    private const TIMEZONE = 'Africa/Kinshasa';

    public function __construct(
        private readonly string $organization,
        private readonly string $changedBy,
        /** Opérateur (clé) et numéro masqué, ou banque et 4 derniers chiffres. */
        private readonly ?string $operator,
        private readonly string $destination,
        private readonly Carbon $activeFrom,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $destination = $this->operator === null ? $this->destination : __('payouts.operators.'.$this->operator).' '.$this->destination;
        $values = ['organization' => $this->organization, 'name' => $this->changedBy, 'destination' => $destination];
        $mail = (new MailMessage)
            ->subject(__('payouts.mail.subject', $values))
            ->greeting(__('payouts.mail.greeting'))
            ->line(__('payouts.mail.intro', $values));

        if ($this->activeFrom->isFuture()) {
            $date = $this->activeFrom->copy()->timezone(self::TIMEZONE)->locale(app()->getLocale())->translatedFormat(__('payouts.mail.date_format'));
            $mail->line(__('payouts.mail.delay', ['date' => $date]));
        }

        return $mail
            ->action(__('payouts.mail.action'), config('app.frontend_url').'/versements')
            ->line(__('payouts.mail.not_you'));
    }
}
