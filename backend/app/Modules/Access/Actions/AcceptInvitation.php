<?php

namespace App\Modules\Access\Actions;

use App\Models\User;
use App\Modules\Access\Models\Invitation;
use App\Modules\Tenancy\Models\Membership;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;

/** Fait entrer l'utilisateur dans l'organisation avec le rôle prévu. À appeler dans le contexte de l'organisation. */
final class AcceptInvitation
{
    public function handle(Invitation $invitation, Organization $organization, User $user): void
    {
        DB::transaction(function () use ($invitation, $organization, $user) {
            // Déjà membre (par exemple propriétaire) : l'invitation ne change pas son rôle.
            if (! $organization->members()->whereKey($user->getKey())->exists()) {
                $organization->users()->syncWithoutDetaching([$user->getKey() => ['status' => Membership::ACTIVE]]);
                $user->unsetRelation('roles')->syncRoles([$invitation->role]);
            }
            $invitation->delete();
        });
    }
}
