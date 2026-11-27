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
use Illuminate\Support\Collection;

/**
 * Statistiques d'un événement de l'organisation courante : ventes jour par jour, par type de billet,
 * par moyen de paiement et par source, visites de la page, entrées heure par heure.
 * Les ventes ne comptent que les commandes payées de la page publique (pas les invitations nominatives) ;
 * les entrées comptent tous les billets valables, invitations comprises. Jours et heures à l'heure du lieu.
 */
final class EventStatistics
{
    /** Courbe des ventes : au plus un an (les jours les plus récents). */
    private const MAX_DAYS = 366;

    /** Entrées : les 72 dernières heures de scan (un scan d'essai la semaine d'avant ne cache pas la soirée). */
    private const MAX_HOURS = 72;

    /** Billets d'une commande (sous-requête corrélée sur orders). */
    private const ORDER_TICKETS = '(select sum(quantity) from order_items where order_items.order_id = orders.id)';

    public function __construct(private readonly Event $event) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tz = $this->event->timezone;
        $paid = $this->paidSales()->toBase()->selectRaw(
            'count(*) as orders, coalesce(sum(total), 0) as revenue, count(*) filter (where total > 0) as paying, coalesce(sum('.self::ORDER_TICKETS.'), 0) as tickets'
        )->first();
        $commission = (int) self::commissions($this->paidSales()->select('id'))->sum();
        $visits = EventVisit::where('event_id', $this->event->id)->toBase()
            ->selectRaw("to_char(day, 'YYYY-MM-DD') as day, source, visits")->get();
        $issued = Ticket::where('event_id', $this->event->id)
            ->whereHas('order', fn (Builder $query) => $query->where('status', Order::PAID))->count();
        $entries = $this->entriesByHour($tz);

        $capacity = $this->event->capacity ?? (int) $this->event->ticketTypes->sum('quantity');
        $revenue = (int) $paid->revenue;
        $orders = (int) $paid->orders;
        $ticketsSold = (int) $paid->tickets;
        $visitCount = (int) $visits->sum('visits');
        $admitted = (int) $entries->sum('admitted');

        return [
            'currency' => $this->event->currency,
            'summary' => [
                'revenue' => $revenue,
                'net' => $revenue - $commission,
                'orders' => $orders,
                'tickets_sold' => $ticketsSold,
                'capacity' => $capacity,
                'fill_rate' => $capacity > 0 ? round($ticketsSold / $capacity, 4) : null,
                'average_order' => (int) $paid->paying > 0 ? intdiv($revenue, (int) $paid->paying) : 0,
                'visits' => $visitCount,
                'conversion' => $visitCount > 0 ? round(min(1, $orders / $visitCount), 4) : null,
                'tickets_issued' => $issued,
                'admitted' => $admitted,
                'people_admitted' => $admitted + (int) $entries->sum('companions'),
                'attendance' => $issued > 0 ? round($admitted / $issued, 4) : null,
            ],
            'daily' => $this->daily($tz, $visits),
            'ticket_types' => $this->ticketTypes(),
            'payments' => $this->payments($orders - (int) $paid->paying),
            'sources' => $this->sources($visits),
            'check_ins' => self::hourly($entries),
        ];
    }

    /** @return Builder<Order> commandes payées de la page publique */
    public function paidSales(): Builder
    {
        return Order::where('event_id', $this->event->id)->paidSales();
    }

    /**
     * Commission kassEvent de chaque commande (montant positif), lue dans le compte de perception.
     *
     * @param  mixed  $orderIds  identifiants, ou sous-requête qui les sélectionne
     * @return Collection<string, int> identifiant de commande => commission
     */
    public static function commissions(mixed $orderIds): Collection
    {
        return LedgerEntry::where('type', LedgerEntry::COMMISSION)->whereIn('order_id', $orderIds)
            ->pluck('amount', 'order_id')->map(fn ($amount) => -(int) $amount);
    }

    /**
     * Jour par jour, jours sans vente ni visite compris (la courbe ne saute pas de jour).
     *
     * @param  Collection<int, object>  $visits
     * @return list<array{date: string, orders: int, tickets: int, revenue: int, visits: int}>
     */
    private function daily(string $tz, Collection $visits): array
    {
        $sales = $this->paidSales()->toBase()
            ->selectRaw("to_char(paid_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'YYYY-MM-DD') as day, count(*) as orders, sum(total) as revenue, sum(".self::ORDER_TICKETS.') as tickets', [$tz])
            ->groupBy('day')->get()->keyBy('day');
        $visitsByDay = $visits->groupBy('day')->map(fn (Collection $rows) => $rows->sum('visits'));

        $days = $sales->keys()->merge($visitsByDay->keys())->unique()->sort()->values();
        if ($days->isEmpty()) {
            return [];
        }

        $last = CarbonImmutable::parse($days->last());
        $first = CarbonImmutable::parse($days->first())->max($last->subDays(self::MAX_DAYS - 1));
        $series = [];
        for ($date = $first; $date->lte($last); $date = $date->addDay()) {
            $key = $date->toDateString();
            $series[] = [
                'date' => $key,
                'orders' => (int) ($sales[$key]->orders ?? 0),
                'tickets' => (int) ($sales[$key]->tickets ?? 0),
                'revenue' => (int) ($sales[$key]->revenue ?? 0),
                'visits' => (int) ($visitsByDay[$key] ?? 0),
            ];
        }

        return $series;
    }

    /** @return list<array<string, mixed>> */
    private function ticketTypes(): array
    {
        $sold = OrderItem::whereIn('order_id', $this->paidSales()->select('id'))->toBase()
            ->selectRaw('ticket_type_id, sum(quantity) as tickets, sum(quantity * unit_price) as revenue')
            ->groupBy('ticket_type_id')->get()->keyBy('ticket_type_id');

        return $this->event->ticketTypes->map(fn ($type) => [
            'id' => $type->id,
            'name' => $type->name,
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
    private function payments(int $free): array
    {
        // Un seul paiement réussi par commande (le dernier) : une commande payée deux fois ne compte qu'une fois.
        $succeeded = Payment::where('status', Payment::SUCCEEDED)
            ->whereIn('order_id', $this->paidSales()->select('id'))->toBase()
            ->selectRaw('distinct on (order_id) order_id, method, operator')
            ->orderBy('order_id')->orderByDesc('id');
        $rows = $this->paidSales()->toBase()
            ->joinSub($succeeded, 'paid_with', 'paid_with.order_id', '=', 'orders.id')
            ->selectRaw('paid_with.method, paid_with.operator, count(*) as orders, sum(orders.total) as revenue')
            ->groupBy('paid_with.method', 'paid_with.operator')->get()
            ->map(fn ($row) => [
                'method' => $row->method,
                'operator' => $row->operator,
                'orders' => (int) $row->orders,
                'revenue' => (int) $row->revenue,
            ]);

        if ($free > 0) {
            $rows->push(['method' => 'free', 'operator' => null, 'orders' => $free, 'revenue' => 0]);
        }

        return $rows->sortByDesc('orders')->values()->all();
    }

    /**
     * Sources : visites de la page, commandes et montant venus de chaque lien partagé.
     *
     * @param  Collection<int, object>  $visits
     * @return list<array{source: string, visits: int, orders: int, revenue: int}>
     */
    private function sources(Collection $visits): array
    {
        $visitsBySource = $visits->groupBy('source')->map(fn (Collection $rows) => $rows->sum('visits'));
        $orders = $this->paidSales()->toBase()
            // « channel » et non « source » : orders a déjà une colonne source (vente ou invitation).
            ->selectRaw("coalesce(referral, 'direct') as channel, count(*) as orders, sum(total) as revenue")
            ->groupBy('channel')->get()->keyBy('channel');

        return $visitsBySource->keys()->merge($orders->keys())->unique()
            ->map(fn (string $source) => [
                'source' => $source,
                'visits' => (int) ($visitsBySource[$source] ?? 0),
                'orders' => (int) ($orders[$source]->orders ?? 0),
                'revenue' => (int) ($orders[$source]->revenue ?? 0),
            ])
            ->sortBy([['revenue', 'desc'], ['orders', 'desc'], ['visits', 'desc']])
            ->values()->all();
    }

    /**
     * Entrées par heure (heure du lieu) des billets encore valables (commande payée) : une invitation
     * retirée après son scan ne compte plus, comme dans les billets émis.
     *
     * @return Collection<string, object>
     */
    private function entriesByHour(string $tz): Collection
    {
        return TicketCheckIn::where('ticket_check_ins.event_id', $this->event->id)->toBase()
            ->join('tickets', 'tickets.id', '=', 'ticket_check_ins.ticket_id')
            ->join('orders', 'orders.id', '=', 'tickets.order_id')
            ->where('orders.status', Order::PAID)
            ->selectRaw("to_char(ticket_check_ins.scanned_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'YYYY-MM-DD\"T\"HH24:00') as hour, count(*) as admitted, sum(tickets.companions) as companions", [$tz])
            ->groupBy('hour')->orderBy('hour')->get()->keyBy('hour');
    }

    /**
     * Courbe des entrées : les 72 dernières heures de scan, heures creuses comprises.
     *
     * @param  Collection<string, object>  $entries
     * @return list<array{hour: string, admitted: int}>
     */
    private static function hourly(Collection $entries): array
    {
        if ($entries->isEmpty()) {
            return [];
        }

        $last = CarbonImmutable::parse($entries->keys()->last());
        $first = CarbonImmutable::parse($entries->keys()->first())->max($last->subHours(self::MAX_HOURS - 1));
        $series = [];
        for ($hour = $first; $hour->lte($last); $hour = $hour->addHour()) {
            $key = $hour->format('Y-m-d\TH:00');
            $series[] = ['hour' => $key, 'admitted' => (int) ($entries[$key]->admitted ?? 0)];
        }

        return $series;
    }
}
