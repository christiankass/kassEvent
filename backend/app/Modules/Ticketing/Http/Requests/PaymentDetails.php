<?php

namespace App\Modules\Ticketing\Http\Requests;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\Phone;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Moyen de paiement choisi : mobile money (opérateur + numéro) ou carte. */
trait PaymentDetails
{
    /** @return array<string, mixed> */
    protected function paymentRules(bool $required): array
    {
        return [
            'payment' => [$required ? 'required' : 'nullable', 'array'],
            'payment.method' => ['required_with:payment', Rule::in([Payment::MOBILE_MONEY, Payment::CARD])],
            'payment.operator' => ['required_if:payment.method,'.Payment::MOBILE_MONEY, 'nullable', Rule::in(array_keys(config('payments.pawapay.operators')))],
            'payment.phone' => ['required_if:payment.method,'.Payment::MOBILE_MONEY, 'nullable', 'string', 'max:20'],
        ];
    }

    protected function validatePhone(Validator $validator): void
    {
        if ($this->input('payment.method') === Payment::MOBILE_MONEY && is_string($this->input('payment.phone'))
            && Phone::normalize($this->input('payment.phone')) === null) {
            $validator->errors()->add('payment.phone', __('ticketing.phone_invalid'));
        }
    }

    /** @return array{method: string, operator: string|null, phone: string|null}|null */
    public function payment(): ?array
    {
        $payment = $this->validated('payment');

        return $payment === null ? null : [
            'method' => $payment['method'],
            'operator' => $payment['operator'] ?? null,
            'phone' => Phone::normalize($payment['phone'] ?? null),
        ];
    }
}
