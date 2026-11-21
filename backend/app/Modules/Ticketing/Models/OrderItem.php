<?php

namespace App\Modules\Ticketing\Models;

use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Ligne de commande : un type de billet, sa quantité et son prix au moment de l'achat. */
#[Fillable(['ticket_type_id', 'name', 'quantity', 'unit_price'])]
class OrderItem extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer'];
    }
}
