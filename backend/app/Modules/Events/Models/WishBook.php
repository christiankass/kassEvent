<?php

namespace App\Modules\Events\Models;

use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Livre de vœux d'un événement. Son identifiant est le lien public à partager : aléatoire (UUID v4,
 * pas un UUID ordonné dans le temps), pour qu'on ne puisse pas deviner celui d'un autre événement.
 */
class WishBook extends Model
{
    use BelongsToOrganization, HasUuids;

    /** Vœux montrés sur la page publique, les plus récents d'abord. */
    public const PUBLIC_LIMIT = 200;

    /**
     * Ton de la page, choisi par l'organisateur selon l'événement : fête (vœux), hommage
     * (condoléances, obsèques) ou livre d'or (tout autre événement). Même liste que la contrainte
     * wish_books_tone_check en base.
     */
    public const TONES = ['celebration', 'tribute', 'guestbook'];

    /** @var array<string, mixed> */
    protected $attributes = ['open' => true, 'public' => true, 'tone' => 'celebration'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    protected function casts(): array
    {
        return ['open' => 'boolean', 'public' => 'boolean'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<Wish, $this> */
    public function wishes(): HasMany
    {
        return $this->hasMany(Wish::class)->latest()->orderByDesc('id');
    }

    /** Page publique où les proches écrivent (à partager sur WhatsApp). */
    public function publicUrl(): string
    {
        return config('app.frontend_url').'/voeux/'.$this->id;
    }
}
