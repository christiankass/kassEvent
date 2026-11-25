<?php

namespace App\Modules\Payouts\Support;

use RuntimeException;

/** Reversement impossible pour l'instant ; $reason est un code stable (traduit dans lang/payouts). */
final class PayoutUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
