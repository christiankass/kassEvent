<?php

namespace App\Modules\Guests\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Invité nominatif d'un événement privé. Il répond (« je viens », « je ne viens pas », avec combien
 * d'accompagnants) par son lien personnel, sans compte ; une fois confirmé, il reçoit son invitation
 * avec QR code (commande gratuite du module Ticketing, order_id).
 */
#[Fillable(['name', 'phone', 'email', 'group_name', 'companions_allowed', 'locale'])]
class Guest extends Model
{
    use BelongsToOrganization, HasUuids;

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const DECLINED = 'declined';

    /** Relance au plus une fois par jour pour un même invité. */
    public const REMIND_AFTER_HOURS = 24;

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING, 'companions_allowed' => 0];

    protected $hidden = ['access_key'];

    protected static function booted(): void
    {
        static::creating(function (Guest $guest) {
            $guest->access_key ??= Str::random(40);
        });
    }

    protected function casts(): array
    {
        return [
            'access_key' => 'encrypted',
            'companions_allowed' => 'integer',
            'companions_confirmed' => 'integer',
            'responded_at' => 'datetime',
            'invited_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<EventTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(EventTable::class, 'table_id');
    }

    /**
     * Places que l'invité occupe : lui et ses accompagnants confirmés ; sans réponse, tous ceux
     * qu'il peut amener (la place est gardée) ; rien s'il a décliné.
     */
    public function party(): int
    {
        return match ($this->status) {
            self::DECLINED => 0,
            self::CONFIRMED => 1 + (int) $this->companions_confirmed,
            default => 1 + $this->companions_allowed,
        };
    }

    public function hasAccessKey(mixed $key): bool
    {
        return is_string($key) && hash_equals($this->access_key, $key);
    }

    /** Lien personnel de réponse de l'invité, à lui envoyer (WhatsApp, e-mail, Messenger). */
    public function rsvpUrl(): string
    {
        return config('app.frontend_url').'/rsvp/'.$this->id.'?cle='.urlencode($this->access_key);
    }

    /** Peut être relancé : jamais invité, ou dernier envoi il y a plus d'un jour. */
    public function canBeReminded(): bool
    {
        return $this->reminded_at === null || $this->reminded_at->lte(now()->subHours(self::REMIND_AFTER_HOURS));
    }
}
