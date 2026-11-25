<?php

namespace App\Modules\Payouts\Models;

use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Un reversement du solde d'une organisation vers son compte de reversement. Son identifiant sert
 * de référence chez pawaPay (payoutId). Le montant est retiré du solde dès sa création, et y revient
 * s'il échoue.
 */
class Payout extends Model
{
    use BelongsToOrganization, HasUuids;

    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const AUTOMATIC = 'automatic';

    public const MANUAL = 'manual';

    protected $guarded = ['id', 'organization_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'completed_at' => 'datetime'];
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
