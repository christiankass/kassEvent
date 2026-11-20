<?php

namespace App\Modules\Ticketing\Support;

use App\Modules\Events\Models\TicketType;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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

        return self::sumByTicketType($ids, Order::whereHoldingSeats(...));
    }

    /**
     * Places vendues (commandes payées) par type de billet.
     *
     * @param  iterable<string>  $ticketTypeIds
     * @return array<string, int>
     */
    public static function sold(iterable $ticketTypeIds): array
    {
        return self::sumByTicketType(collect($ticketTypeIds)->values(), fn (Builder $query) => $query->where('orders.status', Order::PAID));
    }

    /**
     * Verrouille les types de billets (toujours dans l'ordre des identifiants, pour éviter les interblocages)
     * et renvoie leurs places restantes. Toute vente ou modification de quantité passe par là.
     *
     * @param  iterable<string>  $ticketTypeIds
     * @return array{0: Collection<string, TicketType>, 1: array<string, int>} types verrouillés (par identifiant), places restantes
     */
    public static function lockRemaining(iterable $ticketTypeIds): array
    {
        $ticketTypes = TicketType::whereIn('id', collect($ticketTypeIds)->values())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $taken = self::taken($ticketTypes->keys());

        return [$ticketTypes, $ticketTypes->map(fn (TicketType $ticketType) => max(0, $ticketType->quantity - ($taken[$ticketType->id] ?? 0)))->all()];
    }

    /**
     * @param  Collection<int, string>  $ids
     * @param  callable(Builder<OrderItem>): mixed  $orders  filtre sur les commandes comptées (table orders jointe)
     * @return array<string, int>
     */
    private static function sumByTicketType(Collection $ids, callable $orders): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        // Jointure limitée aux lignes des billets demandés (pas de sous-requête sur toutes les commandes de l'organisation).
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.ticket_type_id', $ids)
            ->where($orders)
            ->groupBy('order_items.ticket_type_id')
            ->selectRaw('order_items.ticket_type_id, sum(order_items.quantity) as total')
            ->pluck('total', 'ticket_type_id')
            ->map(fn ($total) => (int) $total)
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
