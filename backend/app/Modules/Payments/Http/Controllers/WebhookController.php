<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\PaymentService;
use App\Modules\Payments\Support\StripeSignature;
use App\Modules\Tenancy\Support\TenantKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications des prestataires. Elles ne servent que de signal : le statut est toujours
 * redemandé au prestataire avant d'être enregistré, une notification falsifiée ne valide donc rien.
 */
final class WebhookController
{
    public function __construct(private readonly PaymentService $payments) {}

    /** pawaPay : résultat d'un dépôt mobile money (depositId = identifiant de notre paiement). */
    public function pawapay(Request $request): JsonResponse
    {
        $this->refresh($request->input('depositId'), 'pawapay');

        return response()->json(['received' => true]);
    }

    /** Stripe : événements Checkout, signés avec le secret du point de terminaison. */
    public function stripe(Request $request): JsonResponse
    {
        abort_unless(StripeSignature::valid(
            $request->getContent(),
            (string) $request->header('Stripe-Signature'),
            (string) config('payments.stripe.webhook_secret'),
        ), 400);

        if (str_starts_with((string) $request->input('type'), 'checkout.session.')) {
            $this->refresh($request->input('data.object.client_reference_id'), 'stripe');
        }

        return response()->json(['received' => true]);
    }

    private function refresh(mixed $paymentId, string $provider): void
    {
        TenantKeys::within(is_string($paymentId) ? $paymentId : null, function () use ($paymentId, $provider) {
            $payment = Payment::where('provider', $provider)->find($paymentId);
            if ($payment !== null) {
                $this->payments->refresh($payment, force: true);
            }
        });
    }
}
