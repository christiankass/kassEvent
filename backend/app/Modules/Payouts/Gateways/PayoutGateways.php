<?php

namespace App\Modules\Payouts\Gateways;

use App\Modules\Payouts\Models\Payout;
use App\Modules\Payouts\Models\PayoutAccount;
use RuntimeException;

/** Prestataire de chaque reversement, selon la configuration (config/payouts.php). */
final class PayoutGateways
{
    public function for(Payout $payout): PayoutGateway
    {
        return match ($payout->provider) {
            'pawapay' => new PawaPayPayouts(
                config('payments.pawapay.urls.'.config('payouts.mode')) ?? throw new RuntimeException('Mode pawaPay inconnu.'),
                (string) config('payments.pawapay.api_token') ?: throw new RuntimeException('PAWAPAY_API_TOKEN manquant.'),
            ),
            'simulated' => app()->isProduction()
                ? throw new RuntimeException('Reversements simulés interdits en production.')
                : new SimulatedPayouts,
            'manual' => new ManualPayouts,
            default => throw new RuntimeException("Prestataire inconnu : {$payout->provider}"),
        };
    }

    /** Prestataire d'un nouveau reversement vers ce compte. */
    public static function providerFor(PayoutAccount $account): string
    {
        if ($account->method === PayoutAccount::BANK) {
            return 'manual';
        }

        return config('payouts.mode') === 'simulated' ? 'simulated' : 'pawapay';
    }
}
