<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Models\Payment;

/** Statut d'un paiement chez le prestataire, tel qu'on l'a vérifié. */
final readonly class GatewayStatus
{
    /** @param  int|null  $amount  montant encaissé en unités mineures, s'il est connu */
    public function __construct(
        public string $status,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?string $failureCode = null,
    ) {}

    public static function pending(): self
    {
        return new self(Payment::PENDING);
    }

    public static function succeeded(int $amount, string $currency): self
    {
        return new self(Payment::SUCCEEDED, $amount, strtoupper($currency));
    }

    public static function failed(string $code): self
    {
        return new self(Payment::FAILED, failureCode: $code);
    }
}
