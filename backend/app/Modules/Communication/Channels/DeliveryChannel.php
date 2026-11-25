<?php

namespace App\Modules\Communication\Channels;

use App\Modules\Communication\Models\Delivery;
use App\Modules\Communication\Support\TicketMessages;

/**
 * Canal d'envoi des billets. Il décrit les messages à envoyer, chacun sous une clé stable (code du
 * billet, accueil…) ; c'est le job qui les envoie, en sautant ceux déjà partis : un nouvel essai
 * après une coupure ne double jamais un billet.
 * Une erreur définitive (numéro sans WhatsApp, conversation fermée) lève DeliveryFailed ;
 * toute autre exception est passagère et l'envoi est retenté.
 */
interface DeliveryChannel
{
    /** @return iterable<string, callable(): string> clé => envoi du message, qui rend son identifiant chez Meta */
    public function parts(Delivery $delivery, TicketMessages $messages): iterable;

    /** Vrai si l'envoi vaut remise (aucun accusé ne viendra ensuite). */
    public function confirmsAtOnce(): bool;
}
