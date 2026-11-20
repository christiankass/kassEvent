<?php

namespace App\Modules\Events\Support;

use Illuminate\Support\Carbon;

/** Dates saisies à l'heure du lieu de l'événement (« 2026-12-24T20:00 ») et enregistrées en UTC. */
final class LocalDateTime
{
    public const FORMAT = 'Y-m-d\TH:i';

    public static function toUtc(?string $value, string $timezone): ?Carbon
    {
        return $value === null ? null : Carbon::createFromFormat(self::FORMAT, $value, $timezone)->utc();
    }

    public static function fromUtc(?Carbon $value, string $timezone): ?string
    {
        return $value?->copy()->setTimezone($timezone)->format(self::FORMAT);
    }
}
