<?php

namespace App\Modules\Guests\Http\Requests;

use App\Modules\Guests\Support\SeatingLayout;
use Illuminate\Foundation\Http\FormRequest;

/** Capacité de la salle, avec soit les places par table, soit le nombre de tables (l'autre est calculé). */
final class SeatingPlanRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hall_capacity' => ['required', 'integer', 'min:1', 'max:'.SeatingLayout::MAX_CAPACITY],
            'seats_per_table' => ['nullable', 'integer', 'min:'.SeatingLayout::MIN_SEATS, 'max:'.SeatingLayout::MAX_SEATS, 'prohibits:table_count'],
            'table_count' => ['nullable', 'integer', 'min:1', 'max:'.SeatingLayout::MAX_TABLES],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $capacity = (int) $this->input('hall_capacity');
            $count = $this->input('table_count');
            if (is_int($count) && $capacity > 0 && intdiv($capacity, max(1, $count)) + 1 > SeatingLayout::MAX_SEATS) {
                $validator->errors()->add('table_count', __('guests.too_few_tables', ['max' => SeatingLayout::MAX_SEATS]));
            }
            $seats = $this->input('seats_per_table') ?? SeatingLayout::DEFAULT_SEATS;
            if (is_int($seats) && $count === null && $seats > 0 && (int) ceil($capacity / $seats) > SeatingLayout::MAX_TABLES) {
                $validator->errors()->add('seats_per_table', __('guests.too_many_tables', ['max' => SeatingLayout::MAX_TABLES]));
            }
        }];
    }
}
