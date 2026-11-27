<?php

namespace App\Modules\Contributions\Support;

/**
 * Frais du prestataire payés par le contributeur, en plus de son don (config/contributions.php).
 * Calculés pour que, une fois les frais retenus par le prestataire sur le total, il reste au moins
 * le montant donné : total = (montant + fixe) / (1 − pourcentage), arrondi au-dessus.
 * Même calcul, au centime près, sur la page publique (frontend/src/lib/contributions.ts).
 */
final class ContributionFees
{
    public static function for(int $amount, string $currency, string $method): int
    {
        $basisPoints = (int) round((float) config("contributions.fees.{$method}.percent", 0) * 100);
        $fixed = (int) config("contributions.fees.{$method}.fixed.{$currency}", 0);
        $divisor = 10000 - min(max($basisPoints, 0), 5000);

        $total = intdiv(($amount + $fixed) * 10000 + $divisor - 1, $divisor);
        $fee = $total - $amount;

        // Le franc congolais se paie sans centimes : frais arrondis au franc supérieur.
        return $currency === 'CDF' ? intdiv($fee + 99, 100) * 100 : $fee;
    }

    /**
     * Taux publiés sur la page publique, pour afficher les frais avant le paiement.
     *
     * @return array<string, array{percent: float, fixed: array<string, int>}>
     */
    public static function rates(): array
    {
        return collect(config('contributions.fees'))
            ->map(fn (array $fee) => ['percent' => (float) $fee['percent'], 'fixed' => array_map('intval', $fee['fixed'])])
            ->all();
    }
}
