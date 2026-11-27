<?php

namespace App\Modules\Payments\Models;

use App\Modules\Contributions\Models\Contribution;
use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use App\Modules\Ticketing\Models\Order;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une tentative de paiement d'une commande ou d'une contribution (plusieurs si la personne
 * réessaie). Son identifiant sert de référence chez pawaPay (depositId) et Stripe (client_reference_id).
 */
class Payment extends Model
{
    use BelongsToOrganization, HasUuids;

    public const MOBILE_MONEY = 'mobile_money';

    public const CARD = 'card';

    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected $guarded = ['id', 'organization_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Contribution, $this> */
    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
