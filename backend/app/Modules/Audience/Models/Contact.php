<?php

namespace App\Modules\Audience\Models;

use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Une personne du carnet de l'organisation (acheteur ou invité). Elle ne reçoit de campagnes que si
 * elle a donné son accord et ne s'est pas désinscrite depuis (scope reachable).
 */
class Contact extends Model
{
    use BelongsToOrganization, HasUuids;

    public const CHECKOUT = 'checkout';

    public const RSVP = 'rsvp';

    protected $guarded = ['id', 'organization_id'];

    protected $hidden = ['unsubscribe_key'];

    protected function casts(): array
    {
        return [
            'marketing_consent' => 'boolean',
            'consented_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'orders_count' => 'integer',
            'invitations_count' => 'integer',
            'unsubscribe_key' => 'encrypted',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeReachable(Builder $query): void
    {
        $query->where('marketing_consent', true)->whereNull('unsubscribed_at');
    }

    public function isReachable(): bool
    {
        return $this->marketing_consent && $this->unsubscribed_at === null;
    }

    public function hasUnsubscribeKey(mixed $key): bool
    {
        return is_string($key) && hash_equals($this->unsubscribe_key, $key);
    }

    /** Désinscription en un clic depuis la messagerie (en-tête List-Unsubscribe d'une campagne). */
    public function apiUnsubscribeUrl(): string
    {
        return route('api.v1.public.contacts.unsubscribe', ['id' => $this->id]).'?key='.urlencode($this->unsubscribe_key);
    }

    /** Lien de désinscription, à mettre dans chaque campagne. */
    public function unsubscribeUrl(): string
    {
        return config('app.frontend_url').'/desinscription/'.$this->id.'?cle='.urlencode($this->unsubscribe_key);
    }
}
