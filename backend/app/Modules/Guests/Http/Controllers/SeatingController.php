<?php

namespace App\Modules\Guests\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Guests\Http\Requests\SeatingPlanRequest;
use App\Modules\Guests\Http\Requests\TableRequest;
use App\Modules\Guests\Http\Resources\GuestResource;
use App\Modules\Guests\Models\EventTable;
use App\Modules\Guests\Models\Guest;
use App\Modules\Guests\Support\Seating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/** Plan de salle d'un événement de l'organisation courante (events.manage). */
final class SeatingController
{
    public function __construct(private readonly Seating $seating) {}

    /** Capacité de la salle : les tables et leurs places sont calculées. */
    public function plan(SeatingPlanRequest $request, string $eventId): JsonResponse
    {
        $event = Event::findOrFail($eventId);
        $tables = $this->seating->plan($event, $request->integer('hall_capacity'), $request->validated('seats_per_table'), $request->validated('table_count'));

        return response()->json(['tables' => self::tablesPayload($tables)]);
    }

    public function storeTable(TableRequest $request, string $eventId): JsonResponse
    {
        $event = Event::findOrFail($eventId);
        $table = $this->seating->addTable($event, $request->integer('seats'), $request->changes()['name'] ?? null);

        return response()->json(['data' => self::tablePayload($table->setAttribute('occupied', 0))], 201);
    }

    public function updateTable(TableRequest $request, string $id): JsonResponse
    {
        $table = $this->seating->updateTable($this->findTable($id), $request->changes());

        return response()->json(['data' => self::tablePayload($table->setAttribute('occupied', $this->seating->occupied($table)))]);
    }

    public function destroyTable(string $id): Response
    {
        $this->seating->removeTable($this->findTable($id));

        return response()->noContent();
    }

    /** Place un invité à une table, ou le retire de sa table (table_id nul). */
    public function assign(Request $request, string $id): GuestResource
    {
        $request->validate(['table_id' => ['present', 'nullable', 'uuid']]);
        $guest = Guest::whereHas('event')->findOrFail($id);
        $table = $request->input('table_id') === null ? null
            : EventTable::where('event_id', $guest->event_id)->findOrFail($request->input('table_id'));

        return new GuestResource($this->seating->assign($guest, $table));
    }

    /** Placement automatique des invités sans table. */
    public function auto(string $eventId): JsonResponse
    {
        return response()->json($this->seating->autoPlace(Event::findOrFail($eventId)));
    }

    /**
     * @param  Collection<int, EventTable>  $tables
     * @return list<array<string, mixed>>
     */
    public static function tablesPayload(Collection $tables): array
    {
        return $tables->map(self::tablePayload(...))->values()->all();
    }

    /** @return array<string, mixed> */
    private static function tablePayload(EventTable $table): array
    {
        return [
            'id' => $table->id,
            'number' => $table->number,
            'name' => $table->name,
            'label' => $table->label(),
            'seats' => $table->seats,
            'occupied' => (int) $table->getAttribute('occupied'),
        ];
    }

    private function findTable(string $id): EventTable
    {
        return EventTable::whereHas('event')->findOrFail($id);
    }
}
