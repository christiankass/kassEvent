<?php

namespace App\Modules\Campaigns\Models;

use App\Modules\Audience\Models\Contact;
use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** L'envoi d'une campagne à un contact (en file, parti, échoué, ou sauté s'il s'est désinscrit entre-temps). */
class CampaignMessage extends Model
{
    use BelongsToOrganization, HasUuids;

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public $timestamps = false;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'opened_at' => 'datetime', 'clicked_at' => 'datetime'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
