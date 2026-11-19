<?php

namespace App\Modules\Access\Actions;

use App\Models\User;
use App\Modules\Access\Models\Invitation;
use App\Modules\Access\Notifications\InvitationNotification;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Notification;

/**
 * Crée ou renouvelle l'invitation d'une adresse (nouveau jeton, nouvelle échéance) et l'envoie.
 * À appeler dans le contexte de l'organisation.
 */
final class SendInvitation
{
    public function handle(Organization $organization, string $email, string $role, ?User $inviter): Invitation
    {
        $invitation = Invitation::firstOrNew(['email' => $email]);
        $invitation->role = $role;
        $invitation->invited_by = $inviter?->getKey() ?? $invitation->invited_by;
        $token = $invitation->refreshToken();
        $invitation->save();

        // Langue de l'e-mail : celle du compte s'il existe déjà, sinon celle de l'organisation.
        $locale = User::where('email', $email)->value('locale') ?? $organization->locale;
        $inviterName = $inviter?->name ?? $invitation->inviter?->name ?? $organization->name;

        Notification::route('mail', $email)->notify(
            (new InvitationNotification($invitation, $organization, $token, $inviterName))->locale($locale),
        );

        return $invitation;
    }
}
