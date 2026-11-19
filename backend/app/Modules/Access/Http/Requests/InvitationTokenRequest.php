<?php

namespace App\Modules\Access\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Le lien d'invitation : organisation et jeton. */
final class InvitationTokenRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'organization' => ['required', 'uuid'],
            'token' => ['required', 'string', 'size:48'],
        ];
    }
}
