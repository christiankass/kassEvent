<?php

namespace App\Modules\Guests\Models;

use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Capacité de la salle et places par table, d'où sont calculées les tables (voir SeatingLayout). */
#[Fillable(['event_id', 'hall_capacity', 'seats_per_table'])]
class SeatingPlan extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return ['hall_capacity' => 'integer', 'seats_per_table' => 'integer'];
    }
}
