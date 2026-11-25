<?php

namespace App\Modules\Guests\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Guests\Models\EventTable;
use App\Modules\Guests\Models\Guest;
use App\Modules\Guests\Models\SeatingPlan;
use App\Modules\Ticketing\Actions\IssueInvitation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plan de salle d'un événement : tables calculées depuis la capacité, place de chaque invité, et
 * table imprimée sur son invitation. Une table ne prend jamais plus de personnes qu'elle n'a de
 * places : les tables de l'événement sont verrouillées pendant un placement, deux organisateurs
 * qui placent en même temps ne peuvent pas la remplir au-delà. À appeler dans le contexte de
 * l'organisation de l'événement.
 */
final class Seating
{
    public function __construct(private readonly IssueInvitation $invitations) {}

    /**
     * Enregistre la capacité et recalcule les tables. Les tables gardées (mêmes numéros) gardent
     * leur nom et leurs invités ; celles en trop sont retirées et leurs invités sont à replacer.
     *
     * @return Collection<int, EventTable>
     */
    public function plan(Event $event, int $capacity, ?int $seatsPerTable, ?int $tableCount): Collection
    {
        $seats = SeatingLayout::seats($capacity, $seatsPerTable, $tableCount);

        return DB::transaction(function () use ($event, $capacity, $seats, $seatsPerTable) {
            SeatingPlan::updateOrCreate(['event_id' => $event->id], ['hall_capacity' => $capacity, 'seats_per_table' => $seatsPerTable ?? max($seats)]);

            $tables = $this->lockTables($event)->keyBy('number');
            $removed = $tables->filter(fn (EventTable $table) => $table->number > count($seats));
            if ($removed->isNotEmpty()) {
                $unseated = Guest::whereIn('table_id', $removed->modelKeys())->get();
                EventTable::whereKey($removed->modelKeys())->delete(); // invités : table_id remis à nul par la base
                $this->printSeats($unseated->each->setAttribute('table_id', null));
            }

            $unseated = collect();
            foreach ($seats as $i => $count) {
                $table = $tables->get($i + 1);
                if ($table === null) {
                    EventTable::create(['event_id' => $event->id, 'number' => $i + 1, 'seats' => $count]);

                    continue;
                }
                $table->update(['seats' => $count]);
                // Table devenue trop petite : les derniers placés sont à replacer (jamais plus de personnes que de places).
                $guests = Guest::where('table_id', $table->id)->orderByDesc('updated_at')->get();
                $over = $guests->sum(fn (Guest $g) => $g->party()) - $count;
                foreach ($guests as $guest) {
                    if ($over <= 0) {
                        break;
                    }
                    $over -= $guest->party();
                    $guest->forceFill(['table_id' => null])->save();
                    $unseated->push($guest);
                }
            }
            $this->printSeats($unseated);

            return $this->tables($event);
        });
    }

    /** Table ajoutée à la main (table d'honneur…), après la dernière. */
    public function addTable(Event $event, int $seats, ?string $name): EventTable
    {
        return DB::transaction(function () use ($event, $seats, $name) {
            $number = ($this->lockTables($event)->max('number') ?? 0) + 1;

            return EventTable::create(['event_id' => $event->id, 'number' => $number, 'seats' => $seats, 'name' => $name]);
        });
    }

    /** Nom ou nombre de places changés : moins de places que de personnes déjà placées, refusé. */
    public function updateTable(EventTable $table, array $attributes): EventTable
    {
        return DB::transaction(function () use ($table, $attributes) {
            $this->lockTables($table->event_id);
            $table->refresh();
            if (isset($attributes['seats']) && $attributes['seats'] < $this->occupied($table)) {
                throw ValidationException::withMessages(['seats' => __('guests.table_too_small', ['table' => $table->label(), 'count' => $this->occupied($table)])]);
            }
            $table->update($attributes);
            $this->printSeats($table->guests()->get());

            return $table;
        });
    }

    public function removeTable(EventTable $table): void
    {
        DB::transaction(function () use ($table) {
            $this->lockTables($table->event_id);
            $guests = $table->guests()->get();
            $table->delete();
            $this->printSeats($guests->each->setAttribute('table_id', null));
        });
    }

    /** Place un invité à une table (ou le retire de sa table, avec null). */
    public function assign(Guest $guest, ?EventTable $table): Guest
    {
        return DB::transaction(function () use ($guest, $table) {
            $this->lockTables($guest->event_id);
            $guest->refresh();
            $table?->refresh(); // places lues sous le verrou : une réduction simultanée est prise en compte
            if ($table !== null) {
                if ($guest->status === Guest::DECLINED) {
                    throw ValidationException::withMessages(['table_id' => __('guests.declined_not_seated')]);
                }
                $left = $table->seats - $this->occupied($table, except: $guest);
                if ($guest->party() > $left) {
                    throw ValidationException::withMessages(['table_id' => __('guests.table_full', ['table' => $table->label(), 'left' => max(0, $left), 'party' => $guest->party()])]);
                }
            }
            $guest->forceFill(['table_id' => $table?->id])->save();
            $this->printSeats(collect([$guest]));

            return $guest;
        });
    }

    /**
     * Place les invités sans table (sauf ceux qui ont décliné) : les plus grands groupes d'abord,
     * chacun à une table où sont déjà des invités du même groupe (« Famille du marié ») s'il y a la
     * place, sinon à la première table qui peut le recevoir. Rend le nombre d'invités placés.
     *
     * @return array{placed: int, unplaced: int}
     */
    public function autoPlace(Event $event): array
    {
        return DB::transaction(function () use ($event) {
            $tables = $this->lockTables($event);
            $guests = Guest::where('event_id', $event->id)->get();
            $left = $tables->mapWithKeys(fn (EventTable $table) => [
                $table->id => $table->seats - $guests->where('table_id', $table->id)->sum(fn (Guest $g) => $g->party()),
            ])->all();
            $groupsAt = $guests->whereNotNull('table_id')->groupBy('table_id')->map(fn ($g) => $g->pluck('group_name')->filter()->unique()->all())->all();

            $waiting = $guests->whereNull('table_id')->where('status', '!=', Guest::DECLINED)
                ->sortBy([fn (Guest $a, Guest $b) => $b->party() <=> $a->party(), ['name', 'asc']]);
            $placed = collect();
            foreach ($waiting as $guest) {
                $fits = $tables->filter(fn (EventTable $table) => $left[$table->id] >= $guest->party());
                $table = $fits->first(fn (EventTable $table) => $guest->group_name && in_array($guest->group_name, $groupsAt[$table->id] ?? [], true))
                    ?? $fits->first(fn (EventTable $table) => ! $guest->group_name || ($groupsAt[$table->id] ?? []) === [])
                    ?? $fits->first();
                if ($table === null) {
                    continue;
                }
                $left[$table->id] -= $guest->party();
                $groupsAt[$table->id] = array_values(array_unique([...($groupsAt[$table->id] ?? []), ...array_filter([$guest->group_name])]));
                $guest->forceFill(['table_id' => $table->id])->save();
                $placed->push($guest);
            }
            $this->printSeats($placed);

            return ['placed' => $placed->count(), 'unplaced' => $waiting->count() - $placed->count()];
        });
    }

    /** @return Collection<int, EventTable> tables de l'événement, avec les personnes déjà placées (occupied) */
    public function tables(Event $event): Collection
    {
        $tables = EventTable::where('event_id', $event->id)->orderBy('number')->get();
        $guests = Guest::where('event_id', $event->id)->whereNotNull('table_id')->get()->groupBy('table_id');

        return $tables->each(fn (EventTable $table) => $table->setAttribute('occupied', ($guests[$table->id] ?? collect())->sum(fn (Guest $g) => $g->party())));
    }

    /** Table imprimée sur l'invitation déjà émise de ces invités. */
    public function printSeats(Collection $guests): void
    {
        $labels = EventTable::whereKey($guests->pluck('table_id')->filter()->unique()->values())->get()->keyBy('id');
        foreach ($guests->whereNotNull('order_id') as $guest) {
            $this->invitations->update($guest->order_id, ['seat' => $labels->get($guest->table_id)?->label()]);
        }
    }

    public function occupied(EventTable $table, ?Guest $except = null): int
    {
        return Guest::where('table_id', $table->id)->when($except, fn ($q) => $q->whereKeyNot($except->id))->get()->sum(fn (Guest $g) => $g->party());
    }

    /**
     * Verrouille les tables de l'événement. Toujours avant l'invité : un placement et une réponse
     * simultanés prennent les verrous dans le même ordre (pas d'interblocage).
     *
     * @return Collection<int, EventTable>
     */
    public function lockTables(Event|string $event): Collection
    {
        return EventTable::where('event_id', $event instanceof Event ? $event->id : $event)->orderBy('number')->lockForUpdate()->get();
    }
}
