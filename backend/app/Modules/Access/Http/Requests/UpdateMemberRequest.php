<?php

namespace App\Modules\Access\Http\Requests;

use App\Modules\Access\Roles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateMemberRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['role' => ['required', 'string', Rule::in(Roles::assignableBy($this->user()))]];
    }
}
