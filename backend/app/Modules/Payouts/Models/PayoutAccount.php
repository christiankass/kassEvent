<?php

namespace App\Modules\Payouts\Models;

use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Où l'organisation reçoit l'argent de ses billets : un numéro mobile money ou un compte bancaire. */
class PayoutAccount extends Model
{
    use BelongsToOrganization, HasUuids;

    public const MOBILE_MONEY = 'mobile_money';

    public const BANK = 'bank';

    protected $guarded = ['id', 'organization_id'];

    protected $hidden = ['account_number'];

    protected function casts(): array
    {
        return ['account_number' => 'encrypted', 'active_from' => 'datetime'];
    }

    /** Un compte qui vient de changer ne reçoit rien avant la fin du délai de sécurité. */
    public function isActive(): bool
    {
        return $this->active_from->isPast();
    }

    public function last4(): ?string
    {
        return $this->account_number === null ? null : substr(preg_replace('/\s+/', '', $this->account_number), -4);
    }
}
