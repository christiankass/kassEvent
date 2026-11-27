<?php

namespace App\Modules\Analytics\Support;

use App\Modules\Analytics\Models\EventVisit;
use App\Modules\CheckIn\Models\TicketCheckIn;
use App\Modules\Events\Models\Event;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payouts\Models\LedgerEntry;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Models\OrderItem;
use App\Modules\Ticketing\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Statistiques d'un événement de l'organisation courante : ventes jour par jour, par type de billet,
 * par moyen de paiement et par source, visites de la page, entrées heure par heure.
 * Les ventes ne comptent que les commandes payées de la page publique (pas les invitations nominatives) ;
 * les entrées comptent tous les billets, invitations comprises. Jours et heures à l'heure du lieu.
 */
final class EventStatistics
{
    /** Courbe des ventes : au plus 120 jours (les plus récents). */
    private const MAX_DAYS = 120;

    /** Entrées : au plus 72 heures à partir du premier scan. */
    private const MAX_HOURS = 72;

    public function __construct(private readonly Event $event) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tz = $this->event->timezone;
        $paidOrders = $this->paidSales()->toBase()
            ->selectRaw('count(*) as orders, coalesce(sum(total), 0) as revenue, count(*) filter (where total > 0) as paying')
            ->first();
        $ticketsSold = (int) $this->soldItems()->sum('quantity');
        $visits = (int) EventVisit::where('event_id', $this->event->id)->sum('visits');
        $commission = -(int) LedgerEntry::where('type', LedgerEntry::COMMISSION)
            ->whereIn('order_id', $this->paidSales()->select('id'))->sum('amount');

        $issued = Ticket::where('event_id', $this->event->id)
            ->whereHas('order', fn (Builder $query) => $query->where('status', Order::PAID))->count();
        $admitted = TicketCheckIn::where('ticket_check_ins.event_id', $this->event->id)->toBase()
            ->join('tickets', 'tickets.id', '=', 'ticket_check_ins.ticket_id')
            ->selectRaw('count(*) as tickets, coalesce(sum(tickets.companions), 0) as companions')->first();

        $capacity = $this->event->capacity ?? (int) $this->event->ticketTypes->sum('quantity');
        $revenue = (int) $paidOrders->revenue;
        $orders = (int) $paidOrders->orders;

        return [
            'currency' => $this->event->currency,
            'timezone' => $tz,
            'summary' => [
                'revenue' => $revenue,
                'commission' => $commission,
                'net' => $revenue - $commission,
                'orders' => $orders,
                'tickets_sold' => $ticketsSold,
                'capacity' => $capacity,
                'fill_rate' => $capacity > 0 ? round($ticketsSold / $capacity, 4) : null,
                'average_order' => (int) $paidOrders->paying > 0 ? intdiv($revenue, (int) $paidOrders->paying) : 0,
                'visits' => $visits,
                'conversion' => $visits > 0 ? round(min(1, $orders / $visits), 4) : null,
                'tickets_issued' => $issued,
                'admitted' => (int) $admitted->tickets,
                'people_admitted' => (int) $admitted->tickets + (int) $admitted->companions,
                'attendance' => $issued > 0 ? round((int) $admitted->tickets / $issued, 4) : null,
            ],
            'daily' => $this->daily($tz),
            'ticket_types' => $this->ticketTypes(),
            'payments' => $this->payments(),
            'sources' => $this->sources(),
            'check_ins' => $this->checkInsByHour($tz),
        ];
    }

    /** @return Builder<Order> commandes payées de la page publique */
    public function paidSales(): Builder
    {
        return Order::where('event_id', $this->event->id)->where('source', Order::SALE)->where('status', Order::PAID);
    }

    /** @return Builder<OrderItem> */
    private function soldItems(): Builder
    {
        return OrderItem::whereIn('order_id', $this->paidSales()->select('id'));
    }

    /** @return list<array{date: string, orders: int, tickets: int, revenue: int, visits: int}> */
    private function daily(string $tz): array
    {
        $day = "to_char(paid_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'YYYY-MM-DD')";
        $sales = $this->paidSales()->toBase()
            ->selectRaw("{$day} as day, count(*) as orders, sum(total) as revenue", [$tz])
            ->groupBy('day')->get()->keyBy('day');
        $tickets = $this->soldItems()->toBase()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->selectRaw("to_char(orders.paid_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'YYYY-MM-DD') as day, sum(quantity) as tickets", [$tz])
            ->groupBy('day')->pluck('tickets', 'day');
        $visits = EventVisit::where('event_id', $this->event->id)->toBase()
            ->selectRaw("to_char(day, 'YYYY-MM-DD') as day, sum(visits) as visits")
            ->groupBy('day')->pluck('visits', 'day');

        $days = $sales->keys()->merge($visits->keys())->unique()->sort()->values();
        if ($days->isEmpty()) {
            return [];
        }

        // Jours sans vente ni visite compris : la courbe ne saute pas de jour.
        $last = CarbonImmutable::parse($days->last());
        $first = CarbonImmutable::parse($days->first())->max($last->subDays(self::MAX_DAYS - 1));
        $series = [];
        for ($date = $first; $date->lte($last); $date = $date->addDay()) {
            $key = $date->toDateString();
            $series[] = [
                'date' => $key,
                'orders' => (int) ($sales[$key]->orders ?? 0),
                'tickets' => (int) ($tickets[$key] ?? 0),
                'revenue' => (int) ($sales[$key]->revenue ?? 0),
                'visits' => (int) ($visits[$key] ?? 0),
            ];
        }

        return $series;
    }

    /** @return list<array<string, mixed>> */
    private function ticketTypes(): array
    {
        $sold = $this->soldItems()->toBase()
            ->selectRaw('ticket_type_id, sum(quantity) as tickets, sum(quantity * unit_price) as revenue')
            ->groupBy('ticket_type_id')->get()->keyBy('ticket_type_id');

        return $this->event->ticketTypes->map(fn ($type) => [
            'id' => $type->id,
            'name' => $type->name,
            'price' => $type->price,
            'quantity' => $type->quantity,
            'sold' => (int) ($sold[$type->id]->tickets ?? 0),
            'revenue' => (int) ($sold[$type->id]->revenue ?? 0),
        ])->values()->all();
    }

    /**
     * Moyens de paiement des commandes payées : mobile money par opérateur, carte, gratuit.
     *
     * @return list<array{method: string, operator: ?string, orders: int, revenue: int}>
     */
    private function payments(): array
    {
        $rows = Payment::where('status', Payment::SUCCEEDED)
            ->whereIn('order_id', $this->paidSales()->select('id'))->toBase()
            ->selectRaw('method, operator, count(distinct order_id) as orders, sum(amount) as revenue')
            ->groupBy('method', 'operator')->get()
            ->map(fn ($row) => [
                'method' => $row->method,
                'operator' => $row->operator,
                'orders' => (int) $row->orders,
                'revenue' => (int) $row->revenue,
            ]);

        $free = $this->paidSales()->where('total', 0)->count();
        if ($free > 0) {
            $rows->push(['method' => 'free', 'operator' => null, 'orders' => $free, 'revenue' => 0]);
        }

        return $rows->sortByDesc('orders')->values()->all();
    }

    /**
     * Sources : visites de la page, commandes, billets et montant venus de chaque lien partagé.
     *
     * @return list<array{source: string, visits: int, orders: int, tickets: int, revenue: int}>
     */
    private function sources(): array
    {
        $visits = EventVisit::where('event_id', $this->event->id)->toBase()
            ->selectRaw('source, sum(visits) as visits')->groupBy('source')->pluck('visits', 'source');
        $orders = $this->paidSales()->toBase()
            // « channel » et non « source » : orders a déjà une colonne source (vente ou invitation).
            ->selectRaw("coalesce(referral, 'direct') as channel, count(*) as orders, sum(total) as revenue")
            ->groupBy('channel')->get()->keyBy('channel');
        $tickets = $this->soldItems()->toBase()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->selectRaw("coalesce(orders.referral, 'direct') as channel, sum(quantity) as tickets")
            ->groupBy('channel')->pluck('tickets', 'channel');

        return $visits->keys()->merge($orders->keys())->unique()
            ->map(fn (string $source) => [
                'source' => $source,
                'visits' => (int) ($visits[$source] ?? 0),
                'orders' => (int) ($orders[$source]->orders ?? 0),
                'tickets' => (int) ($tickets[$source] ?? 0),
                'revenue' => (int) ($orders[$source]->revenue ?? 0),
            ])
            ->sortBy([['revenue', 'desc'], ['orders', 'desc'], ['visits', 'desc']])
            ->values()->all();
    }

    /** @return list<array{hour: string, admitted: int}> entrées heure par heure (heure du lieu), heures creuses comprises */
    private function checkInsByHour(string $tz): array
    {
        $counts = TicketCheckIn::where('event_id', $this->event->id)->toBase()
            ->selectRaw("to_char(scanned_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'YYYY-MM-DD\"T\"HH24:00') as hour, count(*) as admitted", [$tz])
            ->groupBy('hour')->orderBy('hour')->pluck('admitted', 'hour');
        if ($counts->isEmpty()) {
            return [];
        }

        $first = CarbonImmutable::parse($counts->keys()->first());
        $last = CarbonImmutable::parse($counts->keys()->last())->min($first->addHours(self::MAX_HOURS - 1));
        $series = [];
        for ($hour = $first; $hour->lte($last); $hour = $hour->addHour()) {
            $key = $hour->format('Y-m-d\TH:00');
            $series[] = ['hour' => $key, 'admitted' => (int) ($counts[$key] ?? 0)];
        }

        return $series;
    }
}
