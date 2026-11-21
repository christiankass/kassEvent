<?php

namespace App\Modules\Ticketing\Support;

use App\Modules\Events\Models\Event;
use App\Modules\Ticketing\Models\Order;
use App\Modules\Ticketing\Models\OrderItem;
use App\Modules\Ticketing\Models\Ticket;

/** Commande fictive, jamais enregistrée, pour l'aperçu de l'atelier et son e-mail de test. */
final class SampleOrder
{
    /** Contient O et U, lettres jamais tirées pour un vrai billet (voir TicketCode) : ne scanne jamais. */
    public const CODE = 'APERCU0EXEMP';

    public static function for(Event $event, string $holder): Order
    {
        $order = new Order(['buyer_name' => $holder, 'reference' => __('designs.sample_reference')]);
        $item = new OrderItem(['name' => $event->ticketTypes()->value('name') ?? __('designs.sample_ticket')]);
        $ticket = (new Ticket(['code' => self::CODE, 'holder_name' => $holder]))->setRelation('item', $item);

        return $order->setRelation('event', $event)->setRelation('tickets', collect([$ticket]));
    }
}
