<?php

namespace App\Modules\Contributions\Http\Requests;

use App\Modules\Ticketing\Http\Requests\PaymentDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Nouvel essai de paiement d'une contribution (après un échec, ou avec un autre moyen). */
final class PayContributionRequest extends FormRequest
{
    use PaymentDetails;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100'],
            'total' => ['required', 'integer'],
            ...$this->paymentRules(required: true),
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validatePhone($validator)];
    }
}
