<?php

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Exceptions\MissingTenantContext;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Scopes\OrganizationScope;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * À utiliser sur tout modèle d'une table métier (colonne organization_id).
 * La table doit aussi recevoir RowLevelSecurity::enable() dans sa migration.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            $current = app(TenantContext::class)->id() ?? throw MissingTenantContext::forWrite($model::class);
            $model->organization_id ??= $current;

            if ($model->organization_id !== $current) {
                throw MissingTenantContext::mismatch($model::class);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('organization_id')) {
                throw MissingTenantContext::mismatch($model::class);
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
