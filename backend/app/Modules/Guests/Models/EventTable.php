<?php

namespace App\Modules\Guests\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Table numérotée du plan de salle d'un événement. */
#[Fillable(['event_id', 'number', 'name', 'seats'])]
class EventTable extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return ['number' => 'integer', 'seats' => 'integer'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<Guest, $this> */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class, 'table_id');
    }

    /** Ce qui est imprimé sur l'invitation et montré au scan : « Table 4 », « Table 1 · Honneur ». */
    public function label(): string
    {
        return 'Table '.$this->number.($this->name ? ' · '.$this->name : '');
    }
}
