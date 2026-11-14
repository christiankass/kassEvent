<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Appartenance d'un utilisateur à une organisation (table organization_user, protégée par RLS). */
class Membership extends Pivot
{
    use HasUuids;

    public const ACTIVE = 'active';

    protected $table = 'organization_user';
}
