<?php

namespace App\Modules\Access\Http\Requests;

use App\Modules\Access\Roles;
use App\Modules\Identity\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreInvitationRequest extends FormRequest
{
    use NormalizesEmail;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:190'],
            'role' => ['required', 'string', Rule::in(Roles::assignableBy($this->user()))],
        ];
    }
}
