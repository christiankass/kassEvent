<?php

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Contracts\Payable;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payments\Gateways\GatewayRegistry;
use App\Modules\Payments\Gateways\GatewayStatus;
use App\Modules\Payments\Gateways\StripeGateway;
use App\Modules\Payments\Models\Payment;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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

    /** Réservation pendant un paiement par carte : la durée de la page Stripe, plus une marge. */
    private const CARD_HOLD_MINUTES = StripeGateway::SESSION_MINUTES + 2;

    private const MOBILE_MONEY_HOLD_MINUTES = 10;

    private const MOBILE_MONEY_EXTENSION_MINUTES = 3;

    public function __construct(private readonly GatewayRegistry $gateways) {}

    /**
     * Lance un paiement pour la commande ou la contribution. Une panne du prestataire n'est pas une erreur pour
     * l'acheteur : le paiement est enregistré comme échoué et il peut réessayer. Exception : un
     * mobile money dont la demande a pu arriver chez pawaPay (délai dépassé, erreur 5xx) reste en
     * attente, et c'est la vérification suivante qui tranche (sinon l'acheteur pourrait valider
     * sur son téléphone un paiement déjà déclaré échoué, et payer deux fois).
     *
     * Rend null, sans rien lancer, s'il y a déjà un paiement en attente (deux essais simultanés) :
     * la base n'en accepte qu'un par commande et par contribution.
     *
     * @param  array{method: string, operator?: string|null, phone?: string|null}  $data
     */
    public function start(Payable $payable, array $data): ?Payment
    {
        $payment = new Payment([
            'method' => $data['method'],
            'provider' => GatewayRegistry::providerFor($data['method']),
            'amount' => $payable->amountToCharge($data['method']),
            'currency' => $payable->paymentCurrency(),
            'operator' => $data['method'] === Payment::MOBILE_MONEY ? $data['operator'] : null,
            'phone' => $data['method'] === Payment::MOBILE_MONEY ? $data['phone'] : null,
        ]);
        $payment->forceFill($payable->paymentOwner());
        try {
            // Transaction imbriquée : l'échec de l'insertion n'annule pas une transaction englobante.
            DB::transaction(fn () => $payment->save());
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        TenantKeys::remember($payment->id, $payment->organization_id);

        try {
            $immediate = $this->gateways->for($payment)->start($payment, $payable, $payable->publicUrl());
        } catch (Throwable $e) {
            report($e);
            $immediate = $payment->method === Payment::MOBILE_MONEY && self::mayHaveReachedProvider($e)
                ? null
                : GatewayStatus::failed('provider_unavailable');
        }
        $payment->save();

        if ($immediate !== null) {
            $this->settle($payment, $immediate);
        } elseif ($payable instanceof Order) {
            // Les places restent réservées tant que le paiement peut aboutir.
            $this->extendHold($payable, $payment->method === Payment::CARD ? self::CARD_HOLD_MINUTES : self::MOBILE_MONEY_HOLD_MINUTES);
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

        // Mobile money toujours en attente : on garde les places le temps que l'opérateur réponde.
        if ($status->status === Payment::PENDING && $payment->method === Payment::MOBILE_MONEY && $payment->order_id !== null) {
            $order = $payment->order;
            if ($order !== null && ! $order->isExpired()) {
                $this->extendHold($order, self::MOBILE_MONEY_EXTENSION_MINUTES);
            }
        }

        return $payment->refresh();
    }

    /**
     * Abandonne un paiement en attente avant d'en lancer un autre (page de carte quittée sans payer).
     * Faux si le prestataire ne peut pas l'annuler : un mobile money attend la réponse de l'opérateur.
     */
    public function cancel(Payment $payment): bool
    {
        try {
            $cancelled = $this->gateways->for($payment)->cancel($payment);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        if ($cancelled) {
            $this->settle($payment, GatewayStatus::failed('cancelled'));
        }

        return $cancelled;
    }

    /**
     * Prolonge la réservation des places, sans dépasser max_hold_minutes depuis la commande :
     * des essais de paiement répétés ne peuvent pas bloquer des places indéfiniment.
     */
    private function extendHold(Order $order, int $minutes): void
    {
        $until = now()->addMinutes($minutes)->min($order->created_at->copy()->addMinutes(config('payments.max_hold_minutes')));

        if ($order->expires_at->lt($until)) {
            $order->forceFill(['expires_at' => $until])->save();
        }
    }

    /** Délai dépassé ou erreur du serveur : la demande a peut-être été enregistrée par le prestataire. */
    public static function mayHaveReachedProvider(Throwable $e): bool
    {
        return $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError());
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
                if ($locked !== null && $status->status === Payment::SUCCEEDED && $locked->status === Payment::FAILED) {
                    Log::error('Paiement encaissé après avoir été déclaré échoué : à rembourser', ['payment' => $locked->id]);
                }

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
