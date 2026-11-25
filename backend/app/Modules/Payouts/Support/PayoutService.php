<?php

namespace App\Modules\Payouts\Support;

use App\Modules\Payments\Gateways\GatewayStatus;
use App\Modules\Payments\Support\PaymentService;
use App\Modules\Payouts\Gateways\PayoutGateways;
use App\Modules\Payouts\Models\LedgerEntry;
use App\Modules\Payouts\Models\Payout;
use App\Modules\Payouts\Models\PayoutAccount;
use App\Modules\Tenancy\Support\TenantKeys;
use App\Modules\Ticketing\Events\OrderPaid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Compte de perception de l'organisation courante : ventes inscrites au journal, soldes, et
 * reversements vers son compte de reversement. Toujours appelé dans le contexte d'une organisation.
 *
 * Un reversement retire son montant du solde dès sa création (sous verrou : deux demandes
 * simultanées ne reversent pas deux fois le même argent), et l'y rend s'il échoue.
 */
final class PayoutService
{
    private const CHECK_EVERY_SECONDS = 4;

    public function __construct(private readonly PayoutGateways $gateways) {}

    /** Écoute les commandes payées (module Ticketing), dans la transaction qui les confirme. */
    public function onOrderPaid(OrderPaid $event): void
    {
        $common = [
            'organization_id' => $event->organizationId,
            'currency' => $event->currency,
            'order_id' => $event->orderId,
            'reference' => $event->reference,
            'label' => Str::limit($event->eventTitle, 157),
            'tickets' => $event->paidTickets,
            'available_at' => now()->addHours(config('payouts.hold_hours')),
        ];

        LedgerEntry::create(['type' => LedgerEntry::SALE, 'amount' => $event->total, ...$common]);
        $commission = Commission::for($event->total, $event->currency, $event->paidTickets);
        LedgerEntry::create(['type' => LedgerEntry::COMMISSION, 'amount' => -$commission, ...$common]);
    }

    /**
     * Soldes par devise, en unités mineures : disponible (reversable), en attente (ventes récentes),
     * ventes, commission, déjà reversé et en cours de reversement.
     *
     * @return Collection<string, array{currency: string, available: int, on_hold: int, sales: int, commission: int, paid_out: int, in_progress: int}>
     */
    public function balances(string $defaultCurrency): Collection
    {
        $ledger = LedgerEntry::query()->toBase()
            ->selectRaw('currency, coalesce(sum(amount), 0) as balance')
            ->selectRaw('coalesce(sum(amount) filter (where available_at <= ?), 0) as available', [now()])
            ->selectRaw("coalesce(sum(amount) filter (where type = 'sale'), 0) as sales")
            ->selectRaw("coalesce(-sum(amount) filter (where type = 'commission'), 0) as commission")
            ->groupBy('currency')->get()->keyBy('currency');
        $payouts = Payout::query()->toBase()
            ->selectRaw('currency, coalesce(sum(amount) filter (where status = ?), 0) as paid_out', [Payout::SUCCEEDED])
            ->selectRaw('coalesce(sum(amount) filter (where status = ?), 0) as in_progress', [Payout::PENDING])
            ->groupBy('currency')->get()->keyBy('currency');

        // Devise de l'organisation d'abord, même sans vente.
        return collect([$defaultCurrency])->merge($ledger->keys()->sort())->merge($payouts->keys())->unique()->values()
            ->mapWithKeys(fn (string $currency) => [$currency => [
                'currency' => $currency,
                'available' => max(0, (int) ($ledger[$currency]->available ?? 0)),
                'on_hold' => (int) ($ledger[$currency]->balance ?? 0) - (int) ($ledger[$currency]->available ?? 0),
                'sales' => (int) ($ledger[$currency]->sales ?? 0),
                'commission' => (int) ($ledger[$currency]->commission ?? 0),
                'paid_out' => (int) ($payouts[$currency]->paid_out ?? 0),
                'in_progress' => (int) ($payouts[$currency]->in_progress ?? 0),
            ]]);
    }

    public static function minimum(string $currency): int
    {
        return (int) config("payouts.minimum.{$currency}", PHP_INT_MAX);
    }

    /**
     * Reverse tout le solde disponible dans cette devise vers le compte de reversement.
     *
     * @throws PayoutUnavailable no_account, account_pending, in_progress, below_minimum
     */
    public function request(string $currency, string $trigger, ?string $userId = null): Payout
    {
        $payout = DB::transaction(function () use ($currency, $trigger, $userId) {
            // Verrou par organisation : les demandes de reversement passent l'une après l'autre.
            $account = PayoutAccount::lockForUpdate()->first() ?? throw new PayoutUnavailable('no_account');

            if (! $account->isActive()) {
                throw new PayoutUnavailable('account_pending');
            }
            if (Payout::where('currency', $currency)->where('status', Payout::PENDING)->exists()) {
                throw new PayoutUnavailable('in_progress');
            }

            $available = (int) LedgerEntry::where('currency', $currency)->where('available_at', '<=', now())->sum('amount');
            if ($available < self::minimum($currency)) {
                throw new PayoutUnavailable('below_minimum');
            }

            $payout = Payout::create([
                'currency' => $currency,
                'amount' => $available,
                'method' => $account->method,
                'provider' => PayoutGateways::providerFor($account),
                'trigger' => $trigger,
                'operator' => $account->operator,
                'phone' => $account->phone,
                'bank_name' => $account->bank_name,
                'account_last4' => $account->last4(),
                'requested_by' => $userId,
            ]);
            LedgerEntry::create([
                'type' => LedgerEntry::PAYOUT, 'currency' => $currency, 'amount' => -$available,
                'payout_id' => $payout->id, 'available_at' => now(),
            ]);
            TenantKeys::remember($payout->id, $payout->organization_id);

            return $payout;
        });

        $this->send($payout);

        return $payout->refresh();
    }

    /**
     * Envoie le reversement au prestataire. Une panne qui a pu laisser la demande arriver chez pawaPay
     * (délai dépassé, erreur 5xx) le laisse en attente : la vérification suivante tranche, sans
     * risque de payer deux fois.
     */
    private function send(Payout $payout): void
    {
        try {
            $immediate = $this->gateways->for($payout)->start($payout);
        } catch (Throwable $e) {
            report($e);
            $immediate = PaymentService::mayHaveReachedProvider($e) ? null : GatewayStatus::failed('provider_unavailable');
        }
        $payout->save();

        if ($immediate !== null) {
            $this->settle($payout, $immediate);
        }
    }

    /** Revérifie un reversement en attente auprès du prestataire ; $force ignore le délai entre deux vérifications. */
    public function refresh(Payout $payout, bool $force = false): Payout
    {
        if (! $payout->isPending()) {
            return $payout;
        }
        if (! $force && ! Cache::add("payout-check:{$payout->id}", true, self::CHECK_EVERY_SECONDS)) {
            return $payout;
        }

        try {
            $this->settle($payout, $this->gateways->for($payout)->check($payout));
        } catch (Throwable $e) {
            report($e);
        }

        return $payout->refresh();
    }

    /** Enregistre le résultat, une seule fois ; un échec rend le montant au solde. */
    public function settle(Payout $payout, GatewayStatus $status, ?string $reference = null): void
    {
        if ($status->status === Payout::PENDING) {
            return;
        }

        DB::transaction(function () use ($payout, $status, $reference) {
            $locked = Payout::lockForUpdate()->find($payout->id);
            if ($locked === null || ! $locked->isPending()) {
                return;
            }

            if ($status->status === Payout::SUCCEEDED && $status->amount !== null
                && ($status->amount !== $locked->amount || $status->currency !== $locked->currency)) {
                Log::error('Montant reversé différent de la demande', [
                    'payout' => $locked->id, 'expected' => [$locked->amount, $locked->currency], 'sent' => [$status->amount, $status->currency],
                ]);
            }

            $locked->forceFill([
                'status' => $status->status,
                'failure_code' => $status->failureCode,
                'provider_reference' => $reference ?? $locked->provider_reference,
                'completed_at' => now(),
            ])->save();

            if ($locked->status === Payout::FAILED) {
                LedgerEntry::create([
                    'type' => LedgerEntry::PAYOUT_REVERSAL, 'currency' => $locked->currency, 'amount' => $locked->amount,
                    'payout_id' => $locked->id, 'available_at' => now(),
                ]);
            }
        });
    }

    /**
     * Reversements automatiques de l'organisation courante : vérifie ceux en cours, puis reverse
     * chaque devise dont le solde disponible atteint le minimum. Après un échec, on attend que le
     * compte de reversement change (ou une demande manuelle) plutôt que de réessayer chaque heure.
     */
    public function runAutomatic(): void
    {
        Payout::where('status', Payout::PENDING)->where('provider', '!=', 'manual')->get()
            ->each(fn (Payout $payout) => $this->refresh($payout, force: true));

        $account = PayoutAccount::first();
        if ($account === null || ! $account->isActive()) {
            return;
        }

        $currencies = LedgerEntry::where('available_at', '<=', now())->groupBy('currency')
            ->havingRaw('sum(amount) > 0')->pluck('currency');

        foreach ($currencies as $currency) {
            $lastFailed = Payout::where('currency', $currency)->where('status', Payout::FAILED)
                ->where('created_at', '>', $account->updated_at)->exists();
            if ($lastFailed) {
                continue;
            }

            try {
                $this->request($currency, Payout::AUTOMATIC);
            } catch (PayoutUnavailable) {
                // Sous le minimum ou déjà en cours : au prochain passage.
            }
        }
    }
}
