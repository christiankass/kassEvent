<?php

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Support\LocalDateTime;
use App\Modules\Events\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/** Type de billet : prix saisi en unités (« 25 », « 25.50 ») ; 0 pour un billet gratuit sur inscription. */
final class TicketTypeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $format = 'date_format:'.LocalDateTime::FORMAT;

        return [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'string', 'regex:'.Money::PATTERN],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'max_per_order' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'sales_start_at' => ['nullable', $format],
            'sales_end_at' => ['nullable', $format, 'after:sales_start_at'],
        ];
    }

    /** Champs prêts pour le modèle, avec les dates à l'heure du lieu de l'événement converties en UTC. */
    public function toAttributes(string $timezone): array
    {
        $data = $this->validated();

        return [
            ...$data,
            'price' => Money::toMinor($data['price']),
            'sales_start_at' => LocalDateTime::toUtc($data['sales_start_at'] ?? null, $timezone),
            'sales_end_at' => LocalDateTime::toUtc($data['sales_end_at'] ?? null, $timezone),
        ];
    }
}
