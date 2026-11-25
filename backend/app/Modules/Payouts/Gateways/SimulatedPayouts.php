<?php

namespace App\Modules\Payouts\Gateways;

use App\Modules\Payments\Gateways\GatewayStatus;
use App\Modules\Payouts\Models\Payout;

/**
 * Reversements mobile money simulés (développement, démonstrations, tests) : « reçus » quelques
 * secondes après l'envoi ; un numéro qui finit par 0000 est refusé. Jamais en production.
 */
final class SimulatedPayouts implements PayoutGateway
{
    public const DECLINED_SUFFIX = '0000';

    public function start(Payout $payout): ?GatewayStatus
    {
        $payout->provider_reference = 'SIM-'.$payout->id;

        return null;
    }

    public function check(Payout $payout): GatewayStatus
    {
        if (str_ends_with((string) $payout->phone, self::DECLINED_SUFFIX)) {
            return GatewayStatus::failed('RECIPIENT_NOT_FOUND');
        }
        if ($payout->created_at->diffInSeconds(now()) < config('payouts.simulated_delay_seconds')) {
            return GatewayStatus::pending();
        }

        return GatewayStatus::succeeded($payout->amount, $payout->currency);
    }
}
