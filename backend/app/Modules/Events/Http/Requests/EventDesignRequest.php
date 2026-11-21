<?php

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Models\EventDesign;
use App\Modules\Events\Support\DesignTemplates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Apparence du billet : couleurs en hexadécimal (« #0B3B3C »), textes courts, choix dans des listes fermées. */
final class EventDesignRequest extends FormRequest
{
    private const HEX = 'regex:/^#[0-9A-Fa-f]{6}$/';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'template' => ['required', Rule::in(DesignTemplates::names())],
            'kind' => ['required', Rule::in(EventDesign::KINDS)],
            'primary_color' => ['required', 'string', self::HEX],
            'accent_color' => ['required', 'string', self::HEX],
            'font' => ['required', Rule::in(EventDesign::FONTS)],
            'headline' => ['nullable', 'string', 'max:60'],
            'message' => ['nullable', 'string', 'max:280'],
            'footer' => ['nullable', 'string', 'max:200'],
            'banner' => ['required', Rule::in(EventDesign::BANNERS)],
        ];
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        $data = $this->validated();

        return [
            ...$data,
            'primary_color' => strtoupper($data['primary_color']),
            'accent_color' => strtoupper($data['accent_color']),
        ];
    }
}
