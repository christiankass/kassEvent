<?php

namespace App\Modules\Ticketing\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Ticketing\Http\Resources\SalesOrderResource;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Support\Inventory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Ventes d'un événement de l'organisation courante (orders.view, double authentification pour les rôles argent). */
final class SalesController
{
    private const LIMIT = 200;

    public function index(string $eventId): AnonymousResourceCollection
    {
        $event = Event::with('ticketTypes')->findOrFail($eventId);

        $paid = Order::where('event_id', $event->id)->where('status', Order::PAID)
            ->selectRaw('count(*) as orders, coalesce(sum(total), 0) as revenue')->toBase()->first();
        $sold = Inventory::sold($event->ticketTypes->modelKeys());

        $orders = Order::where('event_id', $event->id)
            // Commandes en cours ou réglées, plus celles à rembourser (payées sans places).
            ->where(fn ($query) => $query->holdingSeats()->orWhere('status', Order::REFUND_DUE))
            ->with(['items', 'latestPayment'])
            ->orderByDesc('id') // UUID v7 : du plus récent au plus ancien
            ->limit(self::LIMIT)
            ->get();

        return SalesOrderResource::collection($orders)->additional(['summary' => [
            'currency' => $event->currency,
            'revenue' => (int) $paid->revenue,
            'orders' => (int) $paid->orders,
            'tickets_sold' => array_sum($sold),
            'ticket_types' => $event->ticketTypes->map(fn ($ticketType) => [
                'id' => $ticketType->id,
                'name' => $ticketType->name,
                'quantity' => $ticketType->quantity,
                'sold' => $sold[$ticketType->id] ?? 0,
            ]),
        ]]);
    }
}
