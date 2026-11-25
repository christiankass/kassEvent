<?php

namespace App\Modules\CheckIn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Scans envoyés par le téléphone de l'agent : un seul en ligne, ou le lot gardé hors ligne. */
final class SyncScansRequest extends FormRequest
{
    public const MAX = 200;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scans' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'scans.*.id' => ['required', 'uuid', 'distinct'],
            'scans.*.code' => ['required', 'string', 'max:512'],
            'scans.*.scanned_at' => ['nullable', 'date'],
            'scans.*.gate' => ['nullable', 'string', 'max:40'],
            'scans.*.offline' => ['sometimes', 'boolean'],
            // Hors ligne : l'agent a-t-il laissé entrer la personne ?
            'scans.*.admitted' => ['sometimes', 'boolean'],
        ];
    }
}
