<?php

namespace App\Modules\Ticketing\Events;

/**
 * Commande payante réglée et billets émis. Émis dans la transaction qui la confirme : le module
 * Payouts y inscrit la vente au compte de perception de l'organisation, en même temps (ou pas du tout).
 */
final readonly class OrderPaid
{
    public function __construct(
        public string $orderId,
        public string $organizationId,
        public string $reference,
        public string $eventTitle,
        public string $currency,
        public int $total,
        /** Billets payants de la commande (les billets gratuits n'ont pas de commission). */
        public int $paidTickets,
    ) {}
}
