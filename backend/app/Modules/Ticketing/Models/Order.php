<?php

namespace App\Modules\Ticketing\Models;

use App\Modules\Communication\Models\Delivery;
use App\Modules\Events\Models\Event;
use App\Modules\Payments\Contracts\Payable;
use App\Modules\Payments\Models\Payment;
use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Commande d'un participant, sans compte. Tant qu'elle attend son paiement, ses places sont
 * réservées jusqu'à expires_at ; ensuite elles redeviennent disponibles (aucune tâche à lancer :
 * le calcul des places restantes ignore les commandes expirées).
 */
class Order extends Model implements Payable
{
    use BelongsToOrganization, HasUuids;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    /**
     * Payée après l'expiration de sa réservation, alors que ses places ont été vendues entre-temps :
     * aucun billet émis, l'acheteur doit être remboursé.
     */
    public const REFUND_DUE = 'refund_due';

    /**
     * Invitation retirée (module Guests : l'invité s'est désisté ou a été retiré de la liste) :
     * son QR code n'ouvre plus la porte. Elle redevient payée si l'invité confirme de nouveau.
     */
    public const CANCELLED = 'cancelled';

    /** Origine : vente sur la page publique, ou invitation nominative d'un invité (module Guests). */
    public const SALE = 'sale';

    public const INVITATION = 'invitation';

    /** Statut vu par l'acheteur et l'organisateur : une commande en attente dépassée est « expirée ». */
    public const EXPIRED = 'expired';

    protected $guarded = ['id', 'organization_id', 'status', 'paid_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING, 'source' => self::SALE];

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

    /**
     * Envois des billets sur WhatsApp et Messenger (module Communication).
     *
     * @return HasMany<Delivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class)->orderBy('channel');
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

    /** Textes « invitation » plutôt que « billet » : invitation nominative, ou choix de l'atelier. */
    public function usesInvitationWording(): bool
    {
        return $this->source === self::INVITATION || $this->event->ticketDesign()->isInvitation();
    }

    public function displayStatus(): string
    {
        return $this->isExpired() ? self::EXPIRED : $this->status;
    }

    public function hasAccessKey(?string $key): bool
    {
        return is_string($key) && hash_equals($this->access_key, $key);
    }

    /** Page de la commande sur le site (lien personnel de l'acheteur, sans compte). */
    public function publicUrl(): string
    {
        return config('app.frontend_url').'/commande/'.$this->id.'?cle='.urlencode($this->access_key);
    }

    public function paymentOwner(): array
    {
        return ['order_id' => $this->id];
    }

    public function amountToCharge(string $method): int
    {
        return $this->total;
    }

    public function paymentCurrency(): string
    {
        return $this->currency;
    }

    public function paymentReference(): string
    {
        return $this->reference;
    }

    public function paymentLines(string $method): array
    {
        $title = $this->event->title;

        return $this->items->map(fn (OrderItem $item) => [
            'name' => "{$title} · {$item->name}", 'quantity' => $item->quantity, 'unit_amount' => $item->unit_price,
        ])->values()->all();
    }

    public function payerEmail(): ?string
    {
        return $this->buyer_email;
    }

    public function paymentLocale(): string
    {
        return $this->locale;
    }

    /**
     * Commandes qui occupent des places : payées, ou en attente et pas encore expirées.
     *
     * @param  Builder<self>  $query
     */
    public function scopeHoldingSeats(Builder $query): void
    {
        self::whereHoldingSeats($query);
    }

    /** Même condition, sur une requête qui joint la table orders (colonnes qualifiées). */
    public static function whereHoldingSeats(Builder|QueryBuilder $query): void
    {
        $query->where(fn ($q) => $q->where('orders.status', self::PAID)
            ->orWhere(fn ($q) => $q->where('orders.status', self::PENDING)->where('orders.expires_at', '>', now())));
    }
}
