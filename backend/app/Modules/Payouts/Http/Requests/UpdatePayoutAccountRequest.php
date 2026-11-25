<?php

namespace App\Modules\Payouts\Http\Requests;

use App\Modules\Payments\Support\Phone;
use App\Modules\Payouts\Models\PayoutAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Compte de reversement : numéro mobile money (opérateur + numéro) ou compte bancaire.
 * Le code de l'application d'authentification est redemandé : une session volée ne suffit pas
 * à détourner l'argent.
 */
final class UpdatePayoutAccountRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $mobile = 'required_if:method,'.PayoutAccount::MOBILE_MONEY;
        $bank = 'required_if:method,'.PayoutAccount::BANK;

        return [
            'method' => ['required', Rule::in([PayoutAccount::MOBILE_MONEY, PayoutAccount::BANK])],
            'operator' => [$mobile, 'nullable', Rule::in(array_keys(config('payments.pawapay.operators')))],
            'phone' => [$mobile, 'nullable', 'string', 'max:20'],
            'bank_name' => [$bank, 'nullable', 'string', 'max:80'],
            'account_holder' => [$bank, 'nullable', 'string', 'max:120'],
            'account_number' => [$bank, 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9 -]{6,40}$/'],
            'swift' => ['nullable', 'string', 'regex:/^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/'],
            'code' => ['required', 'string', 'max:10'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->input('method') === PayoutAccount::MOBILE_MONEY && is_string($this->input('phone'))
                && Phone::normalize($this->input('phone')) === null) {
                $validator->errors()->add('phone', __('ticketing.phone_invalid'));
            }
        }];
    }

    /** @return array<string, string|null> */
    public function account(): array
    {
        $data = $this->validated();
        $mobile = $data['method'] === PayoutAccount::MOBILE_MONEY;

        return [
            'method' => $data['method'],
            'operator' => $mobile ? $data['operator'] : null,
            'phone' => $mobile ? Phone::normalize($data['phone']) : null,
            'bank_name' => $mobile ? null : trim($data['bank_name']),
            'account_holder' => $mobile ? null : trim($data['account_holder']),
            'account_number' => $mobile ? null : strtoupper(trim($data['account_number'])),
            'swift' => $mobile || empty($data['swift']) ? null : strtoupper($data['swift']),
        ];
    }
}
