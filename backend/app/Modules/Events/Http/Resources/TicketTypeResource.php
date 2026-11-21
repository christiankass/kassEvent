<?php

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\TicketType;
use App\Modules\Events\Support\LocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketType */
final class TicketTypeResource extends JsonResource
{
    /** @param  int  $taken  places vendues ou réservées par une commande en attente */
    public function __construct(TicketType $resource, private readonly ?string $timezone = null, private readonly int $taken = 0)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $timezone = $this->timezone ?? $this->event->timezone;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'free' => $this->isFree(),
            'quantity' => $this->quantity,
            'available' => max(0, $this->quantity - $this->taken),
            'max_per_order' => $this->max_per_order,
            'sales_start_at' => LocalDateTime::fromUtc($this->sales_start_at, $timezone),
            'sales_end_at' => LocalDateTime::fromUtc($this->sales_end_at, $timezone),
            'on_sale' => $this->isOnSale(),
        ];
    }
}
