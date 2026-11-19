<?php

namespace App\Modules\Access\Models;

use App\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Invitation à rejoindre une organisation avec un rôle. Supprimée une fois acceptée ou annulée. */
#[Fillable(['email', 'role', 'invited_by'])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    use BelongsToOrganization, HasUuids;

    public const VALID_DAYS = 7;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Nouveau jeton et nouvelle échéance ; renvoie le jeton en clair, à mettre dans le lien seulement. */
    public function refreshToken(): string
    {
        $token = Str::random(48);
        $this->token_hash = self::hash($token);
        $this->expires_at = now()->addDays(self::VALID_DAYS);

        return $token;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** @param  Builder<self>  $query */
    public function scopeWithToken(Builder $query, string $token): void
    {
        $query->where('token_hash', self::hash($token));
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
