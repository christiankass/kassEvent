<?php

namespace App\Modules\Access\Http\Resources;

use App\Modules\Access\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invitation */
final class InvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'expires_at' => $this->expires_at->toIso8601String(),
            'expired' => $this->isExpired(),
        ];
    }
}
