<?php

namespace App\Modules\Access\Http\Controllers;

use App\Models\User;
use App\Modules\Access\Actions\AcceptInvitation;
use App\Modules\Access\Actions\SendInvitation;
use App\Modules\Access\Http\Requests\InvitationTokenRequest;
use App\Modules\Access\Models\Invitation;
use App\Modules\Tenancy\Http\Resources\OrganizationResource;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Ce que fait la personne invitée depuis le lien reçu par e-mail : voir l'invitation, en demander
 * une nouvelle si elle a expiré, l'accepter. Le lien porte l'organisation, ce qui permet de lire
 * l'invitation dans son contexte (RLS) ; sans le bon jeton, la réponse est 404.
 */
final class InvitationLinkController
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(InvitationTokenRequest $request): JsonResponse
    {
        [$invitation, $organization] = $this->resolve($request);

        return response()->json(['data' => [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'email' => $invitation->email,
            'role' => $invitation->role,
            'inviter' => $invitation->inviter?->name,
            'expired' => $invitation->isExpired(),
            'has_account' => User::where('email', $invitation->email)->exists(),
        ]]);
    }

    /** Lien expiré : un nouveau lien part à l'adresse invitée (et seulement à elle). */
    public function renew(InvitationTokenRequest $request, SendInvitation $send): JsonResponse
    {
        [$invitation, $organization] = $this->resolve($request);

        $this->context->run($organization->id, fn () => $send->handle($organization, $invitation->email, $invitation->role, null));

        return response()->json(['message' => __('access.renewed', ['email' => $invitation->email])], 202);
    }

    public function accept(InvitationTokenRequest $request, AcceptInvitation $accept): JsonResponse
    {
        [$invitation, $organization] = $this->resolve($request);
        $user = $request->user();

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages(['token' => __('access.expired')]);
        }
        if ($invitation->email !== $user->email) {
            throw ValidationException::withMessages(['token' => __('access.wrong_account', ['email' => $invitation->email])]);
        }

        $this->context->run($organization->id, fn () => $accept->handle($invitation, $organization, $user));

        return OrganizationResource::make($organization)->response();
    }

    /** @return array{Invitation, Organization} */
    private function resolve(InvitationTokenRequest $request): array
    {
        $organization = Organization::find($request->validated('organization'));
        $invitation = $organization === null ? null : $this->context->run(
            $organization->id,
            fn () => Invitation::withToken($request->validated('token'))->with('inviter')->first(),
        );

        abort_if($invitation === null, 404);

        return [$invitation, $organization];
    }
}
