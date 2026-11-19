<?php

namespace App\Modules\Access\Http\Controllers;

use App\Modules\Access\Actions\SendInvitation;
use App\Modules\Access\Http\Requests\StoreInvitationRequest;
use App\Modules\Access\Http\Resources\InvitationResource;
use App\Modules\Access\Models\Invitation;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** Invitations en attente de l'organisation courante (permission members.manage). */
final class InvitationController
{
    public function index(): AnonymousResourceCollection
    {
        return InvitationResource::collection(Invitation::orderBy('created_at')->get());
    }

    public function store(StoreInvitationRequest $request, SendInvitation $send): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');
        $email = $request->validated('email');

        // Membre, même suspendu : on ne réintègre pas quelqu'un par une invitation.
        if ($organization->users()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('access.already_member')]);
        }

        $invitation = $send->handle($organization, $email, $request->validated('role'), $request->user());

        return InvitationResource::make($invitation)->response()->setStatusCode(201);
    }

    /** Renvoie l'invitation avec un nouveau lien (l'ancien ne marche plus). */
    public function resend(Request $request, string $id, SendInvitation $send): InvitationResource
    {
        $invitation = Invitation::findOrFail($id);

        return InvitationResource::make(
            $send->handle($request->attributes->get('organization'), $invitation->email, $invitation->role, $request->user()),
        );
    }

    public function destroy(string $id): Response
    {
        Invitation::findOrFail($id)->delete();

        return response()->noContent();
    }
}
