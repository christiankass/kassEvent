<?php

/*
 * Comptes de perception des organisateurs et reversements.
 * kassEvent encaisse les paiements des billets sur ses propres comptes (pawaPay, Stripe), puis
 * reverse à chaque organisation ses ventes, commission retirée, vers son mobile money ou sa banque.
 * Détenir l'argent de tiers peut demander un agrément de la Banque Centrale du Congo : on reverse vite.
 */
return [
    // Commission de kassEvent sur chaque commande payée : pourcentage du montant + fixe par billet payant.
    'commission' => [
        'percent' => (float) env('PAYOUT_COMMISSION_PERCENT', 5),
        // Unités mineures par billet payant (100 = 1 USD ; 100 = 1 FC).
        'fixed_per_ticket' => [
            'USD' => (int) env('PAYOUT_COMMISSION_FIXED_USD', 0),
            'CDF' => (int) env('PAYOUT_COMMISSION_FIXED_CDF', 0),
        ],
    ],

    // Délai avant qu'une vente puisse être reversée (contestations de paiement par carte, erreurs).
    'hold_hours' => (int) env('PAYOUT_HOLD_HOURS', 24),

    // Reversement automatique dès que le solde disponible atteint ce minimum (unités mineures).
    'minimum' => [
        'USD' => (int) env('PAYOUT_MINIMUM_USD', 500), // 5 USD
        'CDF' => (int) env('PAYOUT_MINIMUM_CDF', 1000000), // 10 000 FC
    ],

    // Un nouveau compte de reversement (changement, pas le premier) ne reçoit rien avant ce délai :
    // le temps pour les responsables, prévenus par e-mail, de réagir à un changement frauduleux.
    'account_change_delay_hours' => (int) env('PAYOUT_ACCOUNT_CHANGE_DELAY_HOURS', 24),

    // Reversements mobile money : « simulated » (par défaut, sans compte), « sandbox » ou « live » (pawaPay).
    // Les virements bancaires sont faits à la main par l'équipe kassEvent (commande payouts:complete).
    'mode' => env('PAYOUT_MODE', env('PAWAPAY_MODE', 'simulated')),

    // Mode simulé : délai avant qu'un reversement soit « reçu » ; un numéro finissant par 0000 est refusé.
    'simulated_delay_seconds' => (int) env('PAYOUTS_SIMULATED_DELAY', 8),
];
