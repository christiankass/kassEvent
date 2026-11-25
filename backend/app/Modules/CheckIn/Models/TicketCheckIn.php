<?php

namespace App\Modules\CheckIn\Models;

use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entrée d'un billet : le premier scan valide, qui fait foi. */
class TicketCheckIn extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime'];
    }

    /** @return BelongsTo<TicketScan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(TicketScan::class, 'scan_id');
    }
}
