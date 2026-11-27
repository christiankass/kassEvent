<?php

namespace App\Modules\Payments\Contracts;

/**
 * Ce qu'un paiement règle : une commande de billets (module Ticketing) ou une contribution sur un
 * livre d'or (module Contributions). Le module Payments n'en connaît que ce contrat.
 */
interface Payable
{
    /** Colonne du paiement qui désigne ce qu'il règle : ['order_id' => …] ou ['contribution_id' => …]. */
    public function paymentOwner(): array;

    /** Montant à encaisser avec ce moyen de paiement, en unités mineures. */
    public function amountToCharge(string $method): int;

    public function paymentCurrency(): string;

    /** Référence lisible (12 caractères), montrée par l'opérateur sur le téléphone. */
    public function paymentReference(): string;

    /**
     * Lignes de la page de paiement par carte ; leur somme vaut amountToCharge().
     *
     * @return list<array{name: string, quantity: int, unit_amount: int}>
     */
    public function paymentLines(string $method): array;

    public function payerEmail(): ?string;

    public function paymentLocale(): string;

    /** Page où revenir après une page de paiement externe. */
    public function publicUrl(): string;
}
