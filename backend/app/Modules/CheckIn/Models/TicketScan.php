<?php

namespace App\Modules\CheckIn\Models;

use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Un scan à l'entrée, tel que l'agent l'a fait (en ligne ou hors ligne). */
class TicketScan extends Model
{
    use BelongsToOrganization, HasUuids;

    public const VALID = 'valid';

    public const ALREADY_USED = 'already_used';

    public const INVALID = 'invalid';

    public const OTHER_EVENT = 'other_event';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['offline' => 'boolean', 'scanned_at' => 'datetime'];
    }
}
