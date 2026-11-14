<?php

namespace App\Modules\Tenancy\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/** Les rôles spatie lisent l'organisation courante directement dans TenantContext : une seule source. */
final class TenantTeamResolver implements PermissionsTeamResolver
{
    public function getPermissionsTeamId(): int|string|null
    {
        return app(TenantContext::class)->id();
    }

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        app(TenantContext::class)->set($id instanceof Model ? $id->getKey() : $id);
    }
}
