<?php

namespace App\Modules\Ticketing\Http\Requests;

use App\Modules\Communication\Channels\ChannelRegistry;
use App\Modules\Communication\Models\Delivery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Envoi des billets d'une commande payée sur un canal, demandé par l'acheteur (lien personnel). */
final class DeliverOrderRequest extends FormRequest
{
    use WhatsAppDetails;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $channels = ['email', ...array_filter(Delivery::CHANNELS, ChannelRegistry::enabled(...))];

        return [
            'key' => ['required', 'string', 'max:100'],
            'channel' => ['required', Rule::in($channels)],
            'whatsapp' => ['required_if:channel,whatsapp', 'nullable', 'string', 'max:25'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateWhatsApp($validator)];
    }
}
