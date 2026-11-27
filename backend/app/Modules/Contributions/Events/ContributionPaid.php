<?php

namespace App\Modules\Contributions\Events;

/**
 * Contribution encaissée. Émis dans la transaction qui l'enregistre : le module Payouts l'inscrit au
 * compte de perception de l'organisation, sans commission, en même temps (ou pas du tout).
 */
final readonly class ContributionPaid
{
    public function __construct(
        public string $contributionId,
        public string $organizationId,
        public string $reference,
        public string $eventTitle,
        public string $currency,
        /** Montant pour le bénéficiaire, frais du prestataire non compris. */
        public int $amount,
    ) {}
}
