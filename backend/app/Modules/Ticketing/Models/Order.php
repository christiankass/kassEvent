<?php

namespace App\Modules\Ticketing\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Payments\Models\Payment;
use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Commande d'un participant, sans compte. Tant qu'elle attend son paiement, ses places sont
 * réservées jusqu'à expires_at ; ensuite elles redeviennent disponibles (aucune tâche à lancer :
 * le calcul des places restantes ignore les commandes expirées).
 */
class Order extends Model
{
    use BelongsToOrganization, HasUuids;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    /** Statut vu par l'acheteur et l'organisateur : une commande en attente dépassée est « expirée ». */
    public const EXPIRED = 'expired';

    protected $guarded = ['id', 'organization_id', 'status', 'paid_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING];

    protected $hidden = ['access_key'];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'access_key' => 'encrypted',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('created_at');
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class)->orderBy('created_at')->orderBy('code');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Dernière tentative de paiement (identifiants UUID v7, croissants dans le temps).
     *
     * @return HasOne<Payment, $this>
     */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->orderByDesc('id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function isExpired(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isPast();
    }

    public function displayStatus(): string
    {
        return $this->isExpired() ? self::EXPIRED : $this->status;
    }

    public function ticketCount(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function hasAccessKey(?string $key): bool
    {
        return is_string($key) && hash_equals($this->access_key, $key);
    }

    /** Page de la commande sur le site (lien personnel de l'acheteur, sans compte). */
    public function publicUrl(array $query = []): string
    {
        return config('app.frontend_url').'/commande/'.$this->id.'?'.http_build_query(['cle' => $this->access_key, ...$query]);
    }

    /**
     * Commandes qui occupent des places : payées, ou en attente et pas encore expirées.
     *
     * @param  Builder<self>  $query
     */
    public function scopeHoldingSeats(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('status', self::PAID)
            ->orWhere(fn (Builder $q) => $q->where('status', self::PENDING)->where('expires_at', '>', now())));
    }
}
