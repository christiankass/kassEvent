<?php

namespace App\Modules\Payouts\Http\Controllers;

use App\Modules\Payouts\Http\Resources\LedgerEntryResource;
use App\Modules\Payouts\Http\Resources\PayoutAccountResource;
use App\Modules\Payouts\Http\Resources\PayoutResource;
use App\Modules\Payouts\Models\LedgerEntry;
use App\Modules\Payouts\Models\Payout;
use App\Modules\Payouts\Models\PayoutAccount;
use App\Modules\Payouts\Support\PayoutService;
use App\Modules\Payouts\Support\PayoutUnavailable;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Compte de perception de l'organisation courante (payouts.view, double authentification pour
 * les rôles argent) : soldes, compte de reversement, reversements et dernières opérations.
 */
final class PayoutController
{
    private const LIMIT = 50;

    public function __construct(private readonly PayoutService $payouts) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        // Page ouverte pendant un reversement : on en redemande le statut (au plus toutes les quelques secondes).
        $this->payouts->refreshPending();

        $account = PayoutAccount::first();

        return response()->json(['data' => [
            'balances' => $this->payouts->balances($organization->currency)->values(),
            'account' => $account ? PayoutAccountResource::make($account) : null,
            'payouts' => PayoutResource::collection(Payout::orderByDesc('id')->limit(self::LIMIT)->get()),
            'entries' => LedgerEntryResource::collection(LedgerEntry::orderByDesc('id')->limit(self::LIMIT)->get()),
            'settings' => [
                'commission_percent' => (float) config('payouts.commission.percent'),
                'commission_fixed' => config('payouts.commission.fixed_per_ticket'),
                'hold_hours' => (int) config('payouts.hold_hours'),
                'minimum' => config('payouts.minimum'),
                'account_change_delay_hours' => (int) config('payouts.account_change_delay_hours'),
            ],
        ]]);
    }

    /** « Reverser maintenant » : tout le solde disponible dans la devise (payouts.manage). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['currency' => ['required', Rule::in(Organization::CURRENCIES)]]);

        try {
            $payout = $this->payouts->request($data['currency'], Payout::MANUAL, $request->user()->id);
        } catch (PayoutUnavailable $e) {
            return response()->json(['message' => __('payouts.unavailable.'.$e->reason), 'code' => $e->reason], 422);
        }

        return PayoutResource::make($payout)->response()->setStatusCode(201);
    }
}
