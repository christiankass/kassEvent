<?php

namespace App\Modules\Contributions\Http\Resources;

use App\Modules\Contributions\Models\Contribution;
use App\Modules\Contributions\Support\ContributionFees;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contribution vue par son auteur (lien personnel) : état du paiement et frais de chaque moyen,
 * pour réessayer.
 *
 * @mixin Contribution
 */
final class ContributionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payment = $this->latestPayment;
        $book = $this->book;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'contributor_name' => $this->contributor_name,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'book' => [
                'id' => $book->id,
                'tone' => $book->tone,
                'beneficiary' => $book->beneficiary,
                'event_title' => $book->event->title,
                'organization' => $book->event->organization->name,
            ],
            'fees' => [
                Payment::MOBILE_MONEY => ContributionFees::for($this->amount, $this->currency, Payment::MOBILE_MONEY),
                Payment::CARD => ContributionFees::for($this->amount, $this->currency, Payment::CARD),
            ],
            'payment' => $payment ? [
                'method' => $payment->method,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'operator' => $payment->operator,
                'phone' => Phone::mask($payment->phone),
                'failure_code' => $payment->failure_code,
                'redirect_url' => $payment->isPending() ? $payment->redirect_url : null,
                'simulated' => $payment->provider === 'simulated',
            ] : null,
        ];
    }
}
