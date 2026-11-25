<?php

namespace App\Modules\Payouts\Http\Resources;

use App\Modules\Payments\Support\Phone;
use App\Modules\Payouts\Models\PayoutAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compte de reversement, masqué : le numéro complet n'est jamais renvoyé. @mixin PayoutAccount */
final class PayoutAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'method' => $this->method,
            'operator' => $this->operator,
            'phone' => Phone::mask($this->phone),
            'bank_name' => $this->bank_name,
            'account_holder' => $this->account_holder,
            'account_last4' => $this->last4(),
            'active' => $this->isActive(),
            'active_from' => $this->active_from->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
