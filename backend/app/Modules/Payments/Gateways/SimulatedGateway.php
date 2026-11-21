<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Models\Payment;
use App\Modules\Ticketing\Models\Order;
use Illuminate\Support\Facades\URL;

/**
 * Paiements simulés, sans compte chez un prestataire (développement, démonstrations, tests).
 * - Mobile money : « validé sur le téléphone » quelques secondes après le lancement ;
 *   un numéro qui finit par 0000 est refusé (solde insuffisant).
 * - Carte : une page de paiement simulée propose « Payer » ou « Refuser ».
 * Jamais disponible en production (voir GatewayRegistry).
 */
final class SimulatedGateway implements PaymentGateway
{
    public const DECLINED_SUFFIX = '0000';

    public function start(Payment $payment, Order $order, string $returnUrl): ?GatewayStatus
    {
        $payment->provider_reference = 'SIM-'.$payment->id;

        if ($payment->method === Payment::CARD) {
            $payment->redirect_url = URL::temporarySignedRoute(
                'payments.simulator.show',
                now()->addMinutes(StripeGateway::SESSION_MINUTES),
                ['payment' => $payment->id, 'return' => $returnUrl],
            );
        }

        return null;
    }

    /** La page de carte simulée s'abandonne ; un mobile money simulé attend sa réponse, comme un vrai. */
    public function cancel(Payment $payment): bool
    {
        return $payment->method === Payment::CARD;
    }

    public function check(Payment $payment): GatewayStatus
    {
        if ($payment->method !== Payment::MOBILE_MONEY) {
            return GatewayStatus::pending(); // la page simulée décide
        }
        if (str_ends_with((string) $payment->phone, self::DECLINED_SUFFIX)) {
            return GatewayStatus::failed('INSUFFICIENT_BALANCE');
        }
        if ($payment->created_at->diffInSeconds(now()) < config('payments.simulated_delay_seconds')) {
            return GatewayStatus::pending();
        }

        return GatewayStatus::succeeded($payment->amount, $payment->currency);
    }
}
