<?php

namespace App\Modules\Events\Models;

use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use App\Modules\Tenancy\Scopes\OrganizationScope;
use App\Support\Slug;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable(['title', 'description', 'starts_at', 'ends_at', 'timezone', 'venue_name', 'venue_address', 'city', 'capacity', 'currency'])]
class Event extends Model
{
    use BelongsToOrganization, HasUuids, SoftDeletes;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    /** Fuseaux proposés : RDC (Kinshasa UTC+1, Lubumbashi UTC+2) et pays voisins francophones. */
    public const TIMEZONES = ['Africa/Kinshasa', 'Africa/Lubumbashi', 'Africa/Brazzaville', 'Africa/Douala', 'Africa/Abidjan', 'Africa/Dakar', 'Africa/Kigali'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    /** Disque des affiches (public en local, MinIO/S3 en production). */
    public static function mediaDisk(): Filesystem
    {
        return Storage::disk(config('kassevent.media_disk'));
    }

    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            $event->slug ??= Slug::unique($event->title, 80, 'evenement');
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'capacity' => 'integer',
        ];
    }

    /** @return HasMany<TicketType, $this> */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class)->orderBy('position')->orderBy('created_at');
    }

    /** @return HasOne<EventDesign, $this> apparence des billets, absente tant qu'elle n'a pas été personnalisée */
    public function design(): HasOne
    {
        return $this->hasOne(EventDesign::class);
    }

    /** Apparence à appliquer aux billets : celle de l'atelier, sinon celle de kassEvent par défaut. */
    public function ticketDesign(): EventDesign
    {
        return $this->design?->setRelation('event', $this) ?? EventDesign::defaultFor($this);
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path ? self::mediaDisk()->url($this->cover_path) : null;
    }

    /**
     * Page de vente : un événement publié, quelle que soit son organisation (aucun contexte
     * d'organisation dans une requête publique ; la RLS autorise cette lecture-là).
     *
     * @param  Builder<self>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->withoutGlobalScope(OrganizationScope::class)->where('status', self::PUBLISHED)->whereHas('organization');
    }
}
