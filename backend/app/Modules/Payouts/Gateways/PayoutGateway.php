<?php

namespace App\Modules\Payouts\Gateways;

use App\Modules\Payments\Gateways\GatewayStatus;
use App\Modules\Payouts\Models\Payout;

interface PayoutGateway
{
    /**
     * Envoie le reversement au prestataire et renseigne provider_reference. Rend null s'il est accepté
     * (résultat connu plus tard), ou l'échec immédiat. N'enregistre pas $payout.
     */
    public function start(Payout $payout): ?GatewayStatus;

    /** Statut actuel, demandé au prestataire (jamais déduit d'une notification non vérifiée). */
    public function check(Payout $payout): GatewayStatus;
}
