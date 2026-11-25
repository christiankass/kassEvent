<?php

namespace App\Modules\Payouts\Models;

use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Une ligne du compte de perception d'une organisation : vente (+), commission kassEvent (−),
 * reversement (−) ou reversement échoué rendu au solde (+). Jamais modifiée ni supprimée.
 */
class LedgerEntry extends Model
{
    use BelongsToOrganization, HasUuids;

    public const SALE = 'sale';

    public const COMMISSION = 'commission';

    public const PAYOUT = 'payout';

    public const PAYOUT_REVERSAL = 'payout_reversal';

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'tickets' => 'integer', 'available_at' => 'datetime'];
    }

    public static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Le journal du compte de perception ne se modifie pas.'));
        static::deleting(fn () => throw new \LogicException('Le journal du compte de perception ne se modifie pas.'));
    }
}
