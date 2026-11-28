<?php

namespace App\Modules\Communication\Events;

/**
 * Accusé WhatsApp (sent, delivered, read, failed) d'un message dont l'identifiant d'envoi n'est pas
 * un envoi de billets : les annonces de campagne (module Campaigns) le reconnaissent à leur préfixe.
 */
final readonly class WhatsAppStatusReceived
{
    public function __construct(public string $callback, public string $status) {}
}
