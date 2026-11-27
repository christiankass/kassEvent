<?php

namespace App\Modules\Ticketing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Billets d'une commande émis (payée ou gratuite), une fois la confirmation enregistrée :
 * le module Communication envoie alors ceux demandés par WhatsApp ou Messenger, et le module
 * Audience ajoute l'acheteur d'une vente en ligne à son carnet de contacts.
 */
final readonly class TicketsIssued implements ShouldDispatchAfterCommit
{
    /** @param  ?array{name: string, email: ?string, phone: ?string, locale: ?string, marketing_consent: bool}  $buyer  acheteur d'une vente en ligne, sinon null */
    public function __construct(public string $organizationId, public string $orderId, public ?array $buyer = null) {}
}
