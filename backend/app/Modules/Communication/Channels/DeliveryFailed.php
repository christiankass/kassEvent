<?php

namespace App\Modules\Communication\Channels;

use RuntimeException;

/** Refus définitif du canal : inutile de réessayer. $reason est enregistré sur l'envoi. */
final class DeliveryFailed extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Envoi refusé : {$reason}");
    }
}
