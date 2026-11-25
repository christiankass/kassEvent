<?php

namespace App\Modules\Payouts\Gateways;

use App\Modules\Payments\Gateways\GatewayStatus;
use App\Modules\Payments\Support\Amount;
use App\Modules\Payouts\Models\Payout;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Reversement vers un numéro mobile money (M-Pesa, Orange Money, Airtel Money) par pawaPay, API v2.
 * pawaPay prévient par une notification, que l'on vérifie toujours en redemandant le statut.
 * Un même payoutId envoyé deux fois n'est payé qu'une fois (DUPLICATE_IGNORED).
 */
final class PawaPayPayouts implements PayoutGateway
{
    private const NOT_FOUND_AFTER_SECONDS = 120;

    public function __construct(private readonly string $baseUrl, private readonly string $token) {}

    public function start(Payout $payout): ?GatewayStatus
    {
        $response = $this->http()->post('/v2/payouts', [
            'payoutId' => $payout->id,
            'recipient' => [
                'type' => 'MMO',
                'accountDetails' => [
                    'phoneNumber' => $payout->phone,
                    'provider' => config("payments.pawapay.operators.{$payout->operator}"),
                ],
            ],
            'amount' => Amount::toDecimal($payout->amount),
            'currency' => $payout->currency,
            // Texte affiché par l'opérateur : 4 à 22 lettres, chiffres ou espaces.
            'customerMessage' => 'kassEvent reversement',
        ])->throw();

        $payout->provider_reference = $payout->id;

        return match ($response->json('status')) {
            'ACCEPTED', 'DUPLICATE_IGNORED' => null,
            default => GatewayStatus::failed((string) ($response->json('failureReason.failureCode') ?? 'REJECTED')),
        };
    }

    public function check(Payout $payout): GatewayStatus
    {
        $response = $this->http()->get('/v2/payouts/'.$payout->id)->throw();

        if ($response->json('status') !== 'FOUND') {
            // Envoi interrompu (délai dépassé) et jamais enregistré chez pawaPay.
            return $payout->created_at->diffInSeconds(now()) > self::NOT_FOUND_AFTER_SECONDS
                ? GatewayStatus::failed('not_found')
                : GatewayStatus::pending();
        }

        $data = $response->json('data');

        return match ($data['status'] ?? null) {
            'COMPLETED' => GatewayStatus::succeeded(Amount::fromDecimal($data['amount'] ?? null) ?? $payout->amount, (string) ($data['currency'] ?? $payout->currency)),
            'FAILED' => GatewayStatus::failed((string) ($data['failureReason']['failureCode'] ?? 'FAILED')),
            // ACCEPTED, ENQUEUED, PROCESSING, IN_RECONCILIATION : pas encore terminé.
            default => GatewayStatus::pending(),
        };
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->withToken($this->token)->acceptJson()->timeout(15)->connectTimeout(5);
    }
}
