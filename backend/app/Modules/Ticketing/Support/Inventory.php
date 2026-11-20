<?php

namespace App\Modules\Ticketing\Support;

use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Models\OrderItem;

/** Places occupées par type de billet : vendues, plus réservées par une commande en attente non expirée. */
final class Inventory
{
    /**
     * @param  iterable<string>  $ticketTypeIds
     * @return array<string, int> identifiant du type de billet => places occupées
     */
    public static function taken(iterable $ticketTypeIds): array
    {
        $ids = collect($ticketTypeIds)->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return OrderItem::query()
            ->whereIn('ticket_type_id', $ids)
            ->whereIn('order_id', Order::query()->holdingSeats()->select('id'))
            ->groupBy('ticket_type_id')
            ->selectRaw('ticket_type_id, sum(quantity) as taken')
            ->pluck('taken', 'ticket_type_id')
            ->map(fn ($taken) => (int) $taken)
            ->all();
    }

    /** Un type de billet a-t-il déjà été commandé (même sans paiement) ? Il ne peut alors plus être supprimé. */
    public static function everOrdered(string $ticketTypeId): bool
    {
        return OrderItem::where('ticket_type_id', $ticketTypeId)->exists();
    }

    /** Un événement a-t-il des commandes ? */
    public static function eventHasOrders(string $eventId): bool
    {
        return Order::where('event_id', $eventId)->exists();
    }
}
