<?php

/*
 * Contributions sur la page du livre d'or (soutien pour des obsèques, cadeau d'anniversaire).
 * kassEvent ne prend aucune commission : le bénéficiaire reçoit exactement le montant annoncé.
 * Les frais du prestataire (pawaPay, Stripe) sont payés en plus par le contributeur, et affichés
 * avant le paiement. Montants en unités mineures (100 = 1 USD ; 100 = 1 FC).
 */
return [
    // Montant donné (sans les frais) : minimum et maximum par devise.
    'limits' => [
        'USD' => ['min' => (int) env('CONTRIBUTION_MIN_USD', 100), 'max' => (int) env('CONTRIBUTION_MAX_USD', 100000)], // 1 à 1 000 USD
        'CDF' => ['min' => (int) env('CONTRIBUTION_MIN_CDF', 100000), 'max' => (int) env('CONTRIBUTION_MAX_CDF', 250000000)], // 1 000 à 2 500 000 FC
    ],

    // Frais du prestataire, ajoutés au montant : pourcentage du total payé + fixe par paiement.
    // À ajuster sur les contrats réels pawaPay et Stripe.
    'fees' => [
        'mobile_money' => [
            'percent' => (float) env('CONTRIBUTION_FEE_MOBILE_MONEY_PERCENT', 3),
            'fixed' => ['USD' => (int) env('CONTRIBUTION_FEE_MOBILE_MONEY_FIXED_USD', 0), 'CDF' => (int) env('CONTRIBUTION_FEE_MOBILE_MONEY_FIXED_CDF', 0)],
        ],
        'card' => [
            'percent' => (float) env('CONTRIBUTION_FEE_CARD_PERCENT', 3.9),
            'fixed' => ['USD' => (int) env('CONTRIBUTION_FEE_CARD_FIXED_USD', 30), 'CDF' => (int) env('CONTRIBUTION_FEE_CARD_FIXED_CDF', 80000)],
        ],
    ],
];
