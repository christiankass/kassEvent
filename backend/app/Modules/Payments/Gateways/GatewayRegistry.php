<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Models\Payment;
use RuntimeException;

/** Prestataire de chaque moyen de paiement, selon la configuration (config/payments.php). */
final class GatewayRegistry
{
    public function for(Payment $payment): PaymentGateway
    {
        return match ($payment->provider) {
            'pawapay' => new PawaPayGateway(
                config('payments.pawapay.urls.'.config('payments.pawapay.mode')) ?? throw new RuntimeException('Mode pawaPay inconnu.'),
                (string) config('payments.pawapay.api_token') ?: throw new RuntimeException('PAWAPAY_API_TOKEN manquant.'),
            ),
            'stripe' => new StripeGateway((string) config('payments.stripe.secret_key') ?: throw new RuntimeException('STRIPE_SECRET_KEY manquant.')),
            'simulated' => app()->isProduction()
                ? throw new RuntimeException('Paiements simulés interdits en production.')
                : new SimulatedGateway,
            default => throw new RuntimeException("Prestataire inconnu : {$payment->provider}"),
        };
    }

    /** Prestataire qui traitera un nouveau paiement par ce moyen. */
    public static function providerFor(string $method): string
    {
        $mode = $method === Payment::CARD ? config('payments.stripe.mode') : config('payments.pawapay.mode');

        if ($mode === 'simulated') {
            return 'simulated';
        }

        return $method === Payment::CARD ? 'stripe' : 'pawapay';
    }

    public static function simulationEnabled(): bool
    {
        return ! app()->isProduction()
            && in_array('simulated', [config('payments.stripe.mode'), config('payments.pawapay.mode')], true);
    }
}
