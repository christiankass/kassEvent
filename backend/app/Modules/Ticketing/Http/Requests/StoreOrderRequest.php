<?php

namespace App\Modules\Ticketing\Http\Requests;

use App\Modules\Analytics\Support\Referral;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Commande depuis la page publique, sans compte : billets choisis, acheteur et moyen de paiement. */
final class StoreOrderRequest extends FormRequest
{
    use PaymentDetails, WhatsAppDetails;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.ticket_type_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:50'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            // Facultatif : les billets partent aussi sur WhatsApp, dès qu'ils sont émis.
            'whatsapp' => ['nullable', 'string', 'max:25'],
            // Tirée au hasard par la page d'achat : un envoi répété rend la même commande.
            'idempotency_key' => ['nullable', 'uuid'],
            // Source de la visite (lien partagé sur WhatsApp, Facebook…) : sert aux statistiques.
            'referral' => ['nullable', 'string', 'max:40'],
            // Le paiement est obligatoire sauf pour une commande gratuite (vérifié une fois le total connu).
            ...$this->paymentRules(required: false),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return; // lignes mal formées : quantities() ne doit lire que des données validées
                }
                if ($this->quantities() === []) {
                    $validator->errors()->add('items', __('ticketing.choose_tickets'));
                }
                $this->validatePhone($validator);
                $this->validateWhatsApp($validator);
            },
        ];
    }

    /** @return array<string, int> identifiant du type de billet => quantité, sans les lignes à 0 */
    public function quantities(): array
    {
        return collect($this->input('items', []))
            ->filter(fn ($item) => is_array($item) && (int) ($item['quantity'] ?? 0) > 0)
            // UUID en minuscules, comme les renvoie PostgreSQL (la base ignore la casse, pas PHP).
            ->mapWithKeys(fn ($item) => [strtolower((string) $item['ticket_type_id']) => (int) $item['quantity']])
            ->all();
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->validated('idempotency_key');

        return $key === null ? null : strtolower($key);
    }

    public function referral(): string
    {
        return Referral::normalize($this->validated('referral'));
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
