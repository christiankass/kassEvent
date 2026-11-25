<?php

namespace App\Modules\Guests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateGuestRequest extends FormRequest
{
    use GuestFields;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::guestRules(partial: true);
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        return self::clean($this->validated());
    }
}
