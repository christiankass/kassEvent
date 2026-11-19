<?php

namespace App\Modules\Identity\Http\Requests;

use App\Http\Middleware\SetLocale;
use App\Modules\Identity\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    use NormalizesEmail;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'locale' => ['sometimes', Rule::in(SetLocale::SUPPORTED)],
        ];
    }
}
