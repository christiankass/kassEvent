<?php

namespace App\Modules\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Commande depuis la page publique, sans compte : billets choisis, acheteur et moyen de paiement. */
final class StoreOrderRequest extends FormRequest
{
    use PaymentDetails;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.ticket_type_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:50'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            // Le paiement est obligatoire sauf pour une commande gratuite (vérifié une fois le total connu).
            ...$this->paymentRules(required: false),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $validator->errors()->has('items') && $this->quantities() === []) {
                    $validator->errors()->add('items', __('ticketing.choose_tickets'));
                }
                $this->validatePhone($validator);
            },
        ];
    }

    /** @return array<string, int> identifiant du type de billet => quantité, sans les lignes à 0 */
    public function quantities(): array
    {
        return collect($this->input('items', []))
            ->filter(fn ($item) => is_array($item) && (int) ($item['quantity'] ?? 0) > 0)
            ->mapWithKeys(fn ($item) => [(string) $item['ticket_type_id'] => (int) $item['quantity']])
            ->all();
    }

    /** @return array{name: string, email: string} */
    public function buyer(): array
    {
        return [
            'name' => trim($this->validated('name')),
            'email' => mb_strtolower(trim($this->validated('email'))),
        ];
    }
}
