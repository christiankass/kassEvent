<?php

namespace App\Modules\Campaigns\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Campagne e-mail : brouillon, programmée (scheduled_at), en cours d'envoi, envoyée ou annulée.
 * Elle ne part qu'aux contacts joignables (accord donné, pas désinscrits) ayant une adresse e-mail.
 */
class Campaign extends Model
{
    use BelongsToOrganization, HasUuids;

    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    /** Destinataires possibles parmi les contacts joignables. */
    public const AUDIENCES = ['all', 'buyers', 'guests'];

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => self::DRAFT, 'channel' => 'email', 'audience' => 'all'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<CampaignMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(CampaignMessage::class);
    }

    /** Brouillon ou programmée : encore modifiable. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SCHEDULED], true);
    }

    /** Événement mis en avant qui n'est plus publié (le bouton de l'e-mail mènerait à une page fermée). */
    public function eventUnavailable(): bool
    {
        return $this->event_id !== null && ! $this->event?->isPublished();
    }

    public function backToDraft(): void
    {
        $this->forceFill(['status' => self::DRAFT, 'scheduled_at' => null])->save();
    }
}
