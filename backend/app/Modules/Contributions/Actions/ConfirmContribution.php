<?php

namespace App\Modules\Contributions\Actions;

use App\Modules\Contributions\Events\ContributionPaid;
use App\Modules\Contributions\Models\Contribution;
use App\Modules\Payments\Events\PaymentSucceeded;
use Illuminate\Support\Facades\Log;

/** Contribution payée : marquée payée et inscrite au compte de perception, une seule fois. */
final class ConfirmContribution
{
    /** Écoute les paiements réussis (module Payments), dans la transaction qui les enregistre. */
    public function onPaymentSucceeded(PaymentSucceeded $event): void
    {
        $payment = $event->payment;
        if ($payment->contribution_id === null) {
            return;
        }

        $contribution = Contribution::with('book.event')->lockForUpdate()->findOrFail($payment->contribution_id);
        if ($contribution->isPaid()) {
            // Second paiement réussi (carte puis mobile money, par exemple).
            Log::warning('Paiement reçu pour une contribution déjà réglée : à rembourser', ['contribution' => $contribution->id, 'payment' => $payment->id]);

            return;
        }

        $contribution->forceFill(['status' => Contribution::PAID, 'paid_at' => now()])->save();

        event(new ContributionPaid(
            $contribution->id, $contribution->organization_id, $contribution->reference,
            (string) $contribution->book->event?->title, $contribution->currency, $contribution->amount,
        ));
    }
}
