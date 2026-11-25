<?php

namespace App\Modules\Communication\Channels;

use App\Modules\Communication\Models\Delivery;
use App\Modules\Communication\Support\TicketMessages;

/**
 * Canal d'envoi des billets. send() n'envoie que ce qui n'est pas encore parti (delivery->messages,
 * enregistré après chaque message) : un nouvel essai après une coupure ne double pas les billets.
 * Une erreur définitive (numéro sans WhatsApp, conversation fermée) lève DeliveryFailed ;
 * toute autre exception est passagère et l'envoi est retenté.
 */
interface DeliveryChannel
{
    public function send(Delivery $delivery, TicketMessages $messages): void;
}
