<?php

namespace App\Modules\Contributions\Support;

use App\Modules\Tenancy\Models\Organization;

/** Ce que la page publique doit savoir pour proposer de contribuer et afficher les frais avant le paiement. */
final class ContributionOptions
{
    /** @return array<string, mixed> */
    public static function for(string $defaultCurrency, ?string $beneficiary): array
    {
        return [
            'beneficiary' => $beneficiary,
            'default_currency' => $defaultCurrency,
            'currencies' => Organization::CURRENCIES,
            'limits' => collect(Organization::CURRENCIES)->mapWithKeys(fn (string $currency) => [$currency => [
                'min' => (int) config("contributions.limits.{$currency}.min"),
                'max' => (int) config("contributions.limits.{$currency}.max"),
            ]])->all(),
            'fees' => ContributionFees::rates(),
        ];
    }
}
