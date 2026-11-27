<?php

namespace App\Modules\Contributions\Http\Requests;

use App\Modules\Contributions\Support\ContributionFees;
use App\Modules\Events\Support\PlainText;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Ticketing\Http\Requests\PaymentDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Contribution depuis la page du livre d'or : montant donné, devise, nom (ou anonyme), petit mot,
 * moyen de paiement, et total affiché à la personne (montant + frais) : refusé s'il ne correspond
 * plus, pour ne jamais encaisser autre chose que ce qu'elle a vu.
 */
final class StoreContributionRequest extends FormRequest
{
    use PaymentDetails;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', Rule::in(Organization::CURRENCIES)],
            'total' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:60', PlainText::noLinks()],
            'message' => ['nullable', 'string', 'max:280', PlainText::noLinks()],
            'idempotency_key' => ['nullable', 'uuid'],
            ...$this->paymentRules(required: true),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $this->validatePhone($validator);
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $amount = (int) $this->input('amount');
            $currency = (string) $this->input('currency');
            $limits = config("contributions.limits.{$currency}");
            if ($amount < $limits['min'] || $amount > $limits['max'] || ($currency === 'CDF' && $amount % 100 !== 0)) {
                $validator->errors()->add('amount', __('contributions.amount_invalid'));
            } elseif ((int) $this->input('total') !== $amount + ContributionFees::for($amount, $currency, (string) $this->input('payment.method'))) {
                $validator->errors()->add('total', __('contributions.total_changed'));
            }
        }];
    }

    /** @return array{amount: int, currency: string, contributor_name: string|null, message: string|null} */
    public function contribution(): array
    {
        $name = PlainText::clean((string) $this->validated('name'), singleLine: true);
        $message = PlainText::clean((string) $this->validated('message'));

        return [
            'amount' => (int) $this->validated('amount'),
            'currency' => $this->validated('currency'),
            // Nom vide (ou fait d'espaces) : contribution anonyme.
            'contributor_name' => mb_strlen($name) >= 2 ? $name : null,
            'message' => $message !== '' ? $message : null,
        ];
    }

    public function idempotencyKey(): ?string
    {
        return $this->validated('idempotency_key');
    }
}
