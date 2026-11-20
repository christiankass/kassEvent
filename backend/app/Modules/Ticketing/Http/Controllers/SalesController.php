<?php

namespace App\Modules\Ticketing\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Ticketing\Http\Resources\SalesOrderResource;
use App\Modules\Ticketing\Models\Order;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Ventes d'un événement de l'organisation courante (orders.view, double authentification pour les rôles argent). */
final class SalesController
{
    private const LIMIT = 200;

    public function index(string $eventId): AnonymousResourceCollection
    {
        $event = Event::with('ticketTypes')->findOrFail($eventId);

        $paid = Order::where('event_id', $event->id)->where('status', Order::PAID)->with('items')->get();
        $sold = $paid->flatMap->items->groupBy('ticket_type_id')->map->sum('quantity');

        $orders = Order::where('event_id', $event->id)
            ->holdingSeats()
            ->with(['items', 'latestPayment'])
            ->orderByDesc('id') // UUID v7 : du plus récent au plus ancien
            ->limit(self::LIMIT)
            ->get();

        return SalesOrderResource::collection($orders)->additional(['summary' => [
            'currency' => $event->currency,
            'revenue' => (int) $paid->sum('total'),
            'orders' => $paid->count(),
            'tickets_sold' => (int) $sold->sum(),
            'ticket_types' => $event->ticketTypes->map(fn ($ticketType) => [
                'id' => $ticketType->id,
                'name' => $ticketType->name,
                'quantity' => $ticketType->quantity,
                'sold' => (int) ($sold[$ticketType->id] ?? 0),
            ]),
        ]]);
    }
}
