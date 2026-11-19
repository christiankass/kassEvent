<?php

namespace App\Modules\Access\Http\Controllers;

use App\Models\User;
use App\Modules\Access\Http\Requests\UpdateMemberRequest;
use App\Modules\Access\Http\Resources\MemberResource;
use App\Modules\Access\Roles;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Membres de l'organisation courante : liste, changement de rôle, retrait (permission members.manage). */
final class MemberController
{
    /** Les membres, et les rôles que je peux donner (pour l'invitation et le changement de rôle). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $members = self::organization($request)->members()->with('roles')->orderBy('name')->get();

        return MemberResource::collection($members)
            ->additional(['meta' => ['assignable_roles' => Roles::assignableBy($request->user())]]);
    }

    public function update(UpdateMemberRequest $request, string $id): MemberResource
    {
        $member = self::editable($request, $id);
        $member->syncRoles([$request->validated('role')]);

        return MemberResource::make($member->load('roles'));
    }

    public function destroy(Request $request, string $id): Response
    {
        $member = self::editable($request, $id);

        DB::transaction(function () use ($request, $member) {
            $member->syncRoles([]);
            self::organization($request)->users()->detach($member->getKey());
        });

        return response()->noContent();
    }

    private static function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * Le membre visé, s'il peut être modifié : ni soi-même (on ne s'enlève pas ses propres droits),
     * ni le propriétaire (l'organisation en garde toujours un).
     */
    private static function editable(Request $request, string $id): User
    {
        $member = self::organization($request)->members()->with('roles')->findOrFail($id);

        if ($member->is($request->user())) {
            throw ValidationException::withMessages(['member' => __('access.not_yourself')]);
        }
        if ($member->hasRole(Roles::OWNER)) {
            throw ValidationException::withMessages(['member' => __('access.owner_locked')]);
        }

        return $member;
    }
}
