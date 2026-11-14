<?php

namespace App\Modules\Tenancy\Http\Requests;

use App\Modules\Tenancy\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOrganizationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'type' => ['required', Rule::in(Organization::TYPES)],
            'country' => ['sometimes', 'string', 'size:2', 'alpha'],
            'currency' => ['sometimes', Rule::in(Organization::CURRENCIES)],
            'locale' => ['sometimes', Rule::in(Organization::LOCALES)],
        ];
    }
}
