<?php

namespace App\Modules\Ticketing\Models;

use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Billet émis après paiement : une place, un titulaire, un code unique (contenu du QR code).
 * Invitation nominative : accompagnants admis avec l'invité et table où il est placé (seat).
 */
#[Fillable(['order_item_id', 'event_id', 'code', 'holder_name', 'companions', 'seat'])]
class Ticket extends Model
{
    use BelongsToOrganization, HasUuids;

    /** @return BelongsTo<OrderItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Type imprimé sur le billet : « VIP », ou « Invitation +2 · Table 4 » pour un invité placé. */
    public function label(): string
    {
        return implode(' · ', array_filter([
            $this->item->name.($this->companions > 0 ? " +{$this->companions}" : ''),
            $this->seat,
        ]));
    }
}
