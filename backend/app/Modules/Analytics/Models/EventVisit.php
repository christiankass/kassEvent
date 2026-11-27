<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Visites de la page publique d'un événement, un compteur par jour (heure du lieu) et par source. */
class EventVisit extends Model
{
    use BelongsToOrganization, HasUuids;

    public $timestamps = false;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['day' => 'date:Y-m-d', 'visits' => 'integer'];
    }

    /** Une visite de plus, en une requête (deux visites simultanées ne s'écrasent pas). */
    public static function record(Event $event, string $source): void
    {
        app(TenantContext::class)->run($event->organization_id, fn () => static::query()->upsert([[
            'id' => (string) Str::uuid7(),
            'organization_id' => $event->organization_id,
            'event_id' => $event->id,
            'day' => now($event->timezone)->toDateString(),
            'source' => $source,
            'visits' => 1,
        ]], ['event_id', 'day', 'source'], ['visits' => DB::raw('event_visits.visits + 1')]));
    }
}
