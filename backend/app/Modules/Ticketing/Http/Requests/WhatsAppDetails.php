<?php

namespace App\Modules\Ticketing\Http\Requests;

use App\Modules\Communication\Support\WhatsAppNumber;
use Illuminate\Validation\Validator;

/** Numéro WhatsApp où envoyer les billets (RDC à la locale, ou international avec son indicatif). */
trait WhatsAppDetails
{
    protected function validateWhatsApp(Validator $validator): void
    {
        $value = $this->input('whatsapp');
        if (is_string($value) && trim($value) !== '' && WhatsAppNumber::normalize($value) === null) {
            $validator->errors()->add('whatsapp', __('communication.whatsapp_invalid'));
        }
    }

    public function whatsapp(): ?string
    {
        return WhatsAppNumber::normalize($this->validated('whatsapp'));
    }
}
