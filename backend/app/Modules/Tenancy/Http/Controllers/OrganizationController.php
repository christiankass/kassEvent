<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Actions\CreateOrganization;
use App\Modules\Tenancy\Http\Requests\StoreOrganizationRequest;
use App\Modules\Tenancy\Http\Resources\OrganizationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrganizationController
{
    /** Les organisations dont je suis membre actif. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizations = $request->user()->activeOrganizations()->orderBy('name')->get();

        return OrganizationResource::collection($organizations);
    }

    /** Crée une organisation ; son créateur en devient propriétaire. */
    public function store(StoreOrganizationRequest $request, CreateOrganization $create): JsonResponse
    {
        $organization = $create->handle($request->user(), $request->validated());

        return OrganizationResource::make($organization)->response()->setStatusCode(201);
    }

    /** L'organisation courante, avec mes rôles et permissions dans celle-ci. */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();

        return OrganizationResource::make($request->attributes->get('organization'))
            ->additional(['meta' => [
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values(),
            ]])
            ->response();
    }
}
