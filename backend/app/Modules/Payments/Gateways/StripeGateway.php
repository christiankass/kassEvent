<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Contracts\Payable;
use App\Modules\Payments\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Carte bancaire par Stripe Checkout : le participant paie sur la page sécurisée de Stripe,
 * puis revient sur sa page de commande. Aucune donnée de carte ne passe par kassEvent.
 */
final class StripeGateway implements PaymentGateway
{
    /** Stripe impose au moins 30 minutes avant l'expiration d'une page de paiement. */
    public const SESSION_MINUTES = 30;

    public function __construct(private readonly string $secretKey) {}

    public function start(Payment $payment, Payable $payable, string $returnUrl): ?GatewayStatus
    {
        $lines = [];
        foreach ($payable->paymentLines($payment->method) as $i => $line) {
            $lines["line_items[{$i}][quantity]"] = $line['quantity'];
            $lines["line_items[{$i}][price_data][currency]"] = strtolower($payment->currency);
            $lines["line_items[{$i}][price_data][unit_amount]"] = $line['unit_amount'];
            $lines["line_items[{$i}][price_data][product_data][name]"] = $line['name'];
        }

        $session = $this->http()->asForm()->post('/v1/checkout/sessions', [
            'mode' => 'payment',
            'client_reference_id' => $payment->id,
            'metadata[payment_id]' => $payment->id,
            'customer_email' => $payable->payerEmail(),
            'locale' => $payable->paymentLocale() === 'en' ? 'en' : 'fr',
            'expires_at' => now()->addMinutes(self::SESSION_MINUTES)->addMinute()->getTimestamp(),
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            ...$lines,
        ])->throw();

        $payment->provider_reference = $session->json('id');
        $payment->redirect_url = $session->json('url');

        return null;
    }

    public function check(Payment $payment): GatewayStatus
    {
        $session = $this->http()->get('/v1/checkout/sessions/'.$payment->provider_reference)->throw();

        return match (true) {
            $session->json('payment_status') === 'paid' => GatewayStatus::succeeded((int) $session->json('amount_total'), (string) $session->json('currency')),
            $session->json('status') === 'expired' => GatewayStatus::failed('expired'),
            default => GatewayStatus::pending(),
        };
    }

    /** Fait expirer la page de paiement ; refusé par Stripe si elle a déjà été payée. */
    public function cancel(Payment $payment): bool
    {
        if ($payment->provider_reference === null) {
            return true;
        }

        return $this->http()->post('/v1/checkout/sessions/'.$payment->provider_reference.'/expire')->successful();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl('https://api.stripe.com')->withToken($this->secretKey)->acceptJson()->timeout(15)->connectTimeout(5);
    }
}
