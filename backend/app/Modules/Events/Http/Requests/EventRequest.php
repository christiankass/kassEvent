<?php

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\LocalDateTime;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Création ou modification complète d'un événement (le formulaire envoie tous les champs). */
final class EventRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $format = 'date_format:'.LocalDateTime::FORMAT;

        return [
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', $format],
            'ends_at' => ['nullable', $format, 'after:starts_at'],
            'timezone' => ['sometimes', Rule::in(Event::TIMEZONES)],
            'venue_name' => ['required', 'string', 'max:160'],
            'venue_address' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:80'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'currency' => ['sometimes', Rule::in(Organization::CURRENCIES)],
        ];
    }

    /** Champs prêts pour le modèle : dates converties de l'heure locale de l'événement vers UTC. */
    public function toAttributes(?Event $event = null): array
    {
        /** @var Organization $organization */
        $organization = $this->attributes->get('organization');
        $data = $this->validated();
        $timezone = $data['timezone'] ?? $event?->timezone ?? Event::TIMEZONES[0];

        return [
            ...$data,
            'timezone' => $timezone,
            'currency' => $data['currency'] ?? $event?->currency ?? $organization->currency,
            'starts_at' => LocalDateTime::toUtc($data['starts_at'], $timezone),
            'ends_at' => LocalDateTime::toUtc($data['ends_at'] ?? null, $timezone),
        ];
    }
}
