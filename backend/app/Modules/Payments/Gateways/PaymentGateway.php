<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Models\Payment;
use App\Modules\Ticketing\Models\Order;

interface PaymentGateway
{
    /**
     * Lance le paiement chez le prestataire et renseigne provider_reference, redirect_url
     * (paiement par carte : page à ouvrir) ou l'échec immédiat. N'enregistre pas $payment.
     *
     * @param  string  $returnUrl  page de la commande où revenir après une page de paiement externe
     */
    public function start(Payment $payment, Order $order, string $returnUrl): ?GatewayStatus;

    /** Statut actuel, demandé au prestataire (jamais déduit d'une notification non vérifiée). */
    public function check(Payment $payment): GatewayStatus;

    /** Empêche un paiement en attente d'aboutir ; faux si ce n'est pas possible (ou plus possible). */
    public function cancel(Payment $payment): bool;
}
