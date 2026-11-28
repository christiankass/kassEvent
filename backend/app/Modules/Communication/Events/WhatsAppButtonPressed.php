<?php

namespace App\Modules\Communication\Events;

/** Bouton de réponse rapide d'un modèle WhatsApp touché par la personne (ex. « Ne plus recevoir »). */
final readonly class WhatsAppButtonPressed
{
    public function __construct(public string $payload, public string $from) {}
}
