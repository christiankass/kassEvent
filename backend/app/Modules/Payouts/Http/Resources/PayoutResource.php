<?php

namespace App\Modules\Payouts\Http\Resources;

use App\Modules\Payments\Support\Phone;
use App\Modules\Payouts\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payout */
final class PayoutResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'method' => $this->method,
            'operator' => $this->operator,
            'phone' => Phone::mask($this->phone),
            'bank_name' => $this->bank_name,
            'account_last4' => $this->account_last4,
            'failure_code' => $this->failure_code,
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
