<?php

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Le locataire : une entreprise, une agence, une institution, un organisateur ou un particulier. */
#[Fillable(['name', 'slug', 'type', 'country', 'currency', 'locale'])]
class Organization extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPES = ['company', 'agency', 'institution', 'organizer', 'individual'];

    public const CURRENCIES = ['USD', 'CDF'];

    public const LOCALES = ['fr', 'en'];

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->withPivot(['id', 'status'])
            ->withTimestamps();
    }
}
