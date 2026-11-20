<?php

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payments\Gateways\GatewayRegistry;
use App\Modules\Payments\Gateways\GatewayStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cycle de vie d'un paiement : lancement chez le prestataire, vérification de son statut,
 * puis enregistrement du résultat (une seule fois, sous verrou).
 * Un paiement n'est jamais déclaré réussi sur la seule foi d'une notification : on redemande
 * toujours le statut au prestataire, et on contrôle le montant et la devise.
 */
final class PaymentService
{
    /** Délai minimal entre deux vérifications d'un même paiement auprès du prestataire. */
    private const CHECK_EVERY_SECONDS = 4;

    public function __construct(private readonly GatewayRegistry $gateways) {}

    /**
     * Lance un paiement pour la commande. Une panne du prestataire n'est pas une erreur pour
     * l'acheteur : le paiement est enregistré comme échoué et il peut réessayer.
     *
     * @param  array{method: string, operator?: string|null, phone?: string|null}  $data
     */
    public function start(Order $order, array $data): Payment
    {
        $payment = new Payment([
            'method' => $data['method'],
            'provider' => GatewayRegistry::providerFor($data['method']),
            'amount' => $order->total,
            'currency' => $order->currency,
            'operator' => $data['method'] === Payment::MOBILE_MONEY ? $data['operator'] : null,
            'phone' => $data['method'] === Payment::MOBILE_MONEY ? $data['phone'] : null,
        ]);
        $payment->order()->associate($order);
        $payment->save();
        TenantKeys::remember($payment->id, $order->organization_id);

        try {
            $immediate = $this->gateways->for($payment)->start($payment, $order, $order->publicUrl());
        } catch (Throwable $e) {
            report($e);
            $immediate = GatewayStatus::failed('provider_unavailable');
        }
        $payment->save();

        // Les places restent réservées tant que le paiement peut aboutir.
        $holdUntil = now()->addMinutes($payment->method === Payment::CARD ? 32 : 10);
        if ($immediate === null && $order->expires_at->lt($holdUntil)) {
            $order->forceFill(['expires_at' => $holdUntil])->save();
        }

        if ($immediate !== null) {
            $this->settle($payment, $immediate);
        }

        return $payment->refresh();
    }

    /**
     * Revérifie un paiement en attente auprès du prestataire (page de commande consultée,
     * notification reçue). $force ignore le délai minimal entre deux vérifications.
     */
    public function refresh(Payment $payment, bool $force = false): Payment
    {
        if (! $payment->isPending()) {
            return $payment;
        }
        if (! $force && ! Cache::add("payment-check:{$payment->id}", true, self::CHECK_EVERY_SECONDS)) {
            return $payment;
        }

        try {
            $status = $this->gateways->for($payment)->check($payment);
        } catch (Throwable $e) {
            report($e);

            return $payment;
        }

        $this->settle($payment, $status);

        return $payment->refresh();
    }

    /** Enregistre le résultat, une seule fois : les notifications en double ne changent rien. */
    public function settle(Payment $payment, GatewayStatus $status): void
    {
        if ($status->status === Payment::PENDING) {
            return;
        }

        DB::transaction(function () use ($payment, $status) {
            $locked = Payment::lockForUpdate()->find($payment->id);
            if ($locked === null || ! $locked->isPending()) {
                return;
            }

            if ($status->status === Payment::SUCCEEDED && ($status->amount !== $locked->amount || $status->currency !== $locked->currency)) {
                Log::error('Montant encaissé différent de la commande', [
                    'payment' => $locked->id, 'expected' => [$locked->amount, $locked->currency], 'received' => [$status->amount, $status->currency],
                ]);
                $status = GatewayStatus::failed('amount_mismatch');
            }

            $locked->forceFill([
                'status' => $status->status,
                'failure_code' => $status->failureCode,
                'completed_at' => now(),
            ])->save();

            if ($locked->status === Payment::SUCCEEDED) {
                event(new PaymentSucceeded($locked));
            }
        });
    }
}
