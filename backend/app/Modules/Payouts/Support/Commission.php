<?php

namespace App\Modules\Payouts\Support;

/** Commission de kassEvent sur une commande payée (config/payouts.php), jamais plus que la commande. */
final class Commission
{
    public static function for(int $total, string $currency, int $paidTickets): int
    {
        $percent = (int) round($total * (float) config('payouts.commission.percent') / 100);
        $fixed = (int) config("payouts.commission.fixed_per_ticket.{$currency}", 0) * $paidTickets;

        return max(0, min($total, $percent + $fixed));
    }
}
