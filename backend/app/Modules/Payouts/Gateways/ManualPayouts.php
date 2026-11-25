<?php

namespace App\Modules\Payouts\Gateways;

use App\Modules\Payments\Gateways\GatewayStatus;
use App\Modules\Payouts\Models\Payout;

/**
 * Virement bancaire : fait à la main par l'équipe kassEvent, qui l'enregistre ensuite
 * (php artisan payouts:complete). En attente jusque-là.
 */
final class ManualPayouts implements PayoutGateway
{
    public function start(Payout $payout): ?GatewayStatus
    {
        return null;
    }

    public function check(Payout $payout): GatewayStatus
    {
        return GatewayStatus::pending();
    }
}
