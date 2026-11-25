<?php

namespace App\Modules\Payouts\Http\Controllers;

use App\Modules\Payouts\Models\Payout;
use App\Modules\Payouts\Support\PayoutService;
use App\Modules\Tenancy\Support\TenantKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notification pawaPay du résultat d'un reversement (payoutId = identifiant de notre reversement).
 * Simple signal : le statut est redemandé à pawaPay avant d'être enregistré.
 */
final class PayoutWebhookController
{
    public function __invoke(Request $request, PayoutService $payouts): JsonResponse
    {
        $id = $request->input('payoutId');

        TenantKeys::within(is_string($id) ? $id : null, function () use ($id, $payouts) {
            $payout = Payout::where('provider', 'pawapay')->find($id);
            if ($payout !== null) {
                $payouts->refresh($payout, force: true);
            }
        });

        return response()->json(['received' => true]);
    }
}
