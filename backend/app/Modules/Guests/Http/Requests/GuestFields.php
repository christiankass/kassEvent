<?php

namespace App\Modules\Guests\Http\Requests;

use App\Modules\Communication\Support\WhatsAppNumber;

/** Champs d'un invité. Le téléphone (souvent son WhatsApp) est rangé au format international. */
trait GuestFields
{
    public const MAX_COMPANIONS = 20;

    /** @return array<string, mixed> */
    protected static function guestRules(string $prefix = '', bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            "{$prefix}name" => [$required, 'string', 'max:120'],
            "{$prefix}phone" => ['nullable', 'string', 'max:30', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && trim($value) !== '' && WhatsAppNumber::normalize($value) === null) {
                    $fail(__('communication.whatsapp_invalid'));
                }
            }],
            "{$prefix}email" => ['nullable', 'string', 'email:rfc', 'max:254'],
            "{$prefix}group_name" => ['nullable', 'string', 'max:60'],
            "{$prefix}companions_allowed" => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_COMPANIONS],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected static function clean(array $row): array
    {
        foreach (['name', 'email', 'group_name'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = is_string($row[$field]) && trim($row[$field]) !== '' ? trim($row[$field]) : null;
            }
        }
        if (array_key_exists('phone', $row)) {
            $row['phone'] = is_string($row['phone']) && trim($row['phone']) !== '' ? WhatsAppNumber::normalize($row['phone']) : null;
        }
        if (array_key_exists('email', $row) && $row['email'] !== null) {
            $row['email'] = strtolower($row['email']);
        }

        return $row;
    }
}
