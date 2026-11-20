<?php

namespace App\Modules\Ticketing\Http\Resources;

use App\Modules\Ticketing\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Commande vue par l'organisateur, dans la liste des ventes d'un événement. @mixin Order */
final class SalesOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->displayStatus(),
            'total' => $this->total,
            'buyer_name' => $this->buyer_name,
            'items' => $this->items->map(fn ($item) => ['name' => $item->name, 'quantity' => $item->quantity]),
            'payment_method' => $this->latestPayment?->method,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
