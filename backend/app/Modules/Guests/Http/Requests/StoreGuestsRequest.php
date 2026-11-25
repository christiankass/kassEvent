<?php

namespace App\Modules\Guests\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Un invité saisi à la main, ou une liste importée (contacts du téléphone, liste collée). */
final class StoreGuestsRequest extends FormRequest
{
    use GuestFields;

    public const MAX_PER_IMPORT = 500;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['guests' => ['required', 'array', 'min:1', 'max:'.self::MAX_PER_IMPORT], 'guests.*' => ['array'], ...self::guestRules('guests.*.')];
    }

    /** @return list<array<string, mixed>> */
    public function guests(): array
    {
        return array_values(array_map(self::clean(...), $this->validated('guests')));
    }
}
