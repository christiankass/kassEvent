<?php

namespace App\Modules\Guests\Http\Requests;

use App\Modules\Guests\Support\SeatingLayout;
use Illuminate\Foundation\Http\FormRequest;

final class TableRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => ['nullable', 'string', 'max:60'],
            'seats' => [$required, 'integer', 'min:1', 'max:'.SeatingLayout::MAX_SEATS],
        ];
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        $data = $this->validated();
        if (array_key_exists('name', $data)) {
            $data['name'] = is_string($data['name']) && trim($data['name']) !== '' ? trim($data['name']) : null;
        }

        return $data;
    }
}
