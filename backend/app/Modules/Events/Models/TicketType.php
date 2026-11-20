<?php

namespace App\Modules\Events\Models;

use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Type de billet (Standard, VIP…) : prix en unités mineures, 0 pour un billet gratuit sur inscription. */
#[Fillable(['name', 'description', 'price', 'quantity', 'max_per_order', 'sales_start_at', 'sales_end_at', 'position'])]
class TicketType extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'quantity' => 'integer',
            'max_per_order' => 'integer',
            'sales_start_at' => 'datetime',
            'sales_end_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function isFree(): bool
    {
        return $this->price === 0;
    }

    /** En vente maintenant (période de vente ouverte). */
    public function isOnSale(): bool
    {
        $now = now();

        return ($this->sales_start_at === null || $this->sales_start_at->lte($now))
            && ($this->sales_end_at === null || $this->sales_end_at->gt($now));
    }
}
