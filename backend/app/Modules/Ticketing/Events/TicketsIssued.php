<?php

namespace App\Modules\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Billets d'une commande émis (payée ou gratuite), une fois la confirmation enregistrée :
 * le module Communication envoie alors ceux demandés par WhatsApp ou Messenger.
 */
final readonly class TicketsIssued implements ShouldDispatchAfterCommit
{
    public function __construct(public string $organizationId, public string $orderId) {}
}
