<?php

namespace App\Modules\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Nouvel essai de paiement d'une commande en attente (après un échec, ou avec un autre moyen). */
final class PayOrderRequest extends FormRequest
{
    use PaymentDetails;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100'],
            ...$this->paymentRules(required: true),
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validatePhone($validator)];
    }
}
