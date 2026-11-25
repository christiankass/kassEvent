<?php

namespace App\Modules\Guests\Jobs;

use App\Modules\Guests\Mail\GuestInvitationMail;
use App\Modules\Guests\Models\Guest;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/** Envoie l'invitation (lien de réponse) d'un invité par e-mail, dans sa langue. */
final class SendGuestInvitation implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly string $organizationId, public readonly string $guestId)
    {
        $this->onQueue('notifications');
    }

    public function handle(TenantContext $context): void
    {
        $context->run($this->organizationId, function () {
            $guest = Guest::with(['event.organization', 'event.design'])->find($this->guestId);
            if ($guest?->email === null) {
                return; // retiré de la liste, ou adresse effacée entre-temps
            }

            Mail::to($guest->email, $guest->name)->locale($guest->locale)->send(new GuestInvitationMail($guest));
        });
    }
}
