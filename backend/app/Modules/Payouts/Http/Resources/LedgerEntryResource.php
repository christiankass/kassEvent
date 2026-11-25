<?php

namespace App\Modules\Payouts\Http\Resources;

use App\Modules\Payouts\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LedgerEntry */
final class LedgerEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'label' => $this->label,
            'tickets' => $this->tickets,
            'available_at' => $this->available_at->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
