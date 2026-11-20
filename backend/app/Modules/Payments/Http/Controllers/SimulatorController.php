<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Gateways\GatewayStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\PaymentService;
use App\Modules\Tenancy\Support\TenantKeys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/** Page de paiement par carte simulée (mode « simulated », jamais en production). Liens signés. */
final class SimulatorController
{
    public function show(Request $request, string $payment): View
    {
        $found = $this->find($payment);

        return view('payments.simulator', [
            'payment' => $found,
            'action' => URL::temporarySignedRoute('payments.simulator.complete', now()->addMinutes(30), [
                'payment' => $found->id, 'return' => $request->query('return'),
            ]),
        ]);
    }

    public function complete(Request $request, PaymentService $payments, string $payment): RedirectResponse
    {
        TenantKeys::within($payment, function () use ($request, $payments, $payment) {
            $found = Payment::where('provider', 'simulated')->find($payment);
            if ($found !== null) {
                $payments->settle($found, $request->input('outcome') === 'pay'
                    ? GatewayStatus::succeeded($found->amount, $found->currency)
                    : GatewayStatus::failed('declined'));
            }
        });

        return redirect()->away((string) $request->query('return'));
    }

    private function find(string $id): Payment
    {
        return TenantKeys::within($id, fn () => Payment::where('provider', 'simulated')->find($id)) ?? abort(404);
    }
}
