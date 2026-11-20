<?php

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\Payment;

/** Paiement encaissé et vérifié. Émis dans la transaction qui l'enregistre. */
final readonly class PaymentSucceeded
{
    public function __construct(public Payment $payment) {}
}
