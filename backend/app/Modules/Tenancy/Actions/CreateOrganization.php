<?php

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Access\Roles;
use App\Modules\Tenancy\Models\Membership;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;

/** Crée une organisation et en fait de son créateur le propriétaire. */
final class CreateOrganization
{
    public function __construct(private readonly TenantContext $context) {}

    /** @param  array{name: string, type: string, country?: string, currency?: string, locale?: string}  $data */
    public function handle(User $owner, array $data): Organization
    {
        return DB::transaction(function () use ($owner, $data) {
            $organization = Organization::create([
                ...$data,
                'slug' => self::slug($data['name']),
            ]);

            // L'appartenance et le rôle s'écrivent dans le contexte de la nouvelle organisation (RLS).
            $this->context->run($organization->getKey(), function () use ($organization, $owner) {
                $organization->users()->attach($owner->getKey(), ['status' => Membership::ACTIVE]);
                $owner->assignRole(Roles::OWNER);
            });

            return $organization;
        });
    }

    /** Identifiant lisible et unique, dans la limite de la colonne (80 caractères). */
    private static function slug(string $name): string
    {
        return Slug::unique($name, 60, 'organisation');
    }
}
