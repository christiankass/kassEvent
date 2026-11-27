<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Contracts\Payable;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\Amount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Mobile money (M-Pesa, Orange Money, Airtel Money) par pawaPay, API v2.
 * Le participant valide le paiement sur son téléphone ; pawaPay nous prévient ensuite par une
 * notification, que l'on vérifie toujours en redemandant le statut à pawaPay.
 */
final class PawaPayGateway implements PaymentGateway
{
    private const NOT_FOUND_AFTER_SECONDS = 120;

    public function __construct(private readonly string $baseUrl, private readonly string $token) {}

    public function start(Payment $payment, Payable $payable, string $returnUrl): ?GatewayStatus
    {
        $response = $this->http()->post('/v2/deposits', [
            'depositId' => $payment->id,
            'payer' => [
                'type' => 'MMO',
                'accountDetails' => [
                    'phoneNumber' => $payment->phone,
                    'provider' => config("payments.pawapay.operators.{$payment->operator}"),
                ],
            ],
            'amount' => Amount::toDecimal($payment->amount),
            'currency' => $payment->currency,
            'clientReferenceId' => $payable->paymentReference(),
            // Texte affiché par l'opérateur sur le téléphone : 4 à 22 lettres, chiffres ou espaces.
            'customerMessage' => 'kassEvent '.$payable->paymentReference(),
        ])->throw();

        $payment->provider_reference = $payment->id;

        return match ($response->json('status')) {
            'ACCEPTED', 'DUPLICATE_IGNORED' => null,
            default => GatewayStatus::failed((string) ($response->json('failureReason.failureCode') ?? 'REJECTED')),
        };
    }

    public function check(Payment $payment): GatewayStatus
    {
        $response = $this->http()->get('/v2/deposits/'.$payment->id)->throw();

        if ($response->json('status') !== 'FOUND') {
            // Lancement interrompu (délai dépassé) et jamais enregistré chez pawaPay.
            return $payment->created_at->diffInSeconds(now()) > self::NOT_FOUND_AFTER_SECONDS
                ? GatewayStatus::failed('not_found')
                : GatewayStatus::pending();
        }

        $deposit = $response->json('data');

        return match ($deposit['status'] ?? null) {
            'COMPLETED' => ($amount = Amount::fromDecimal($deposit['amount'] ?? null)) === null
                ? GatewayStatus::failed('INVALID_AMOUNT')
                : GatewayStatus::succeeded($amount, (string) ($deposit['currency'] ?? '')),
            'FAILED' => GatewayStatus::failed((string) ($deposit['failureReason']['failureCode'] ?? 'FAILED')),
            // ACCEPTED, SUBMITTED, ENQUEUED, PROCESSING, IN_RECONCILIATION : pas encore terminé.
            default => GatewayStatus::pending(),
        };
    }

    /** Un dépôt mobile money ne s'annule pas : seul l'opérateur (ou son expiration) y met fin. */
    public function cancel(Payment $payment): bool
    {
        return false;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->withToken($this->token)->acceptJson()->timeout(15)->connectTimeout(5);
    }
}
