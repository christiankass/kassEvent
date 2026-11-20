<?php

/*
 * Paiements des billets. Chaque moyen a son mode :
 * - « simulated » (par défaut) : aucun compte nécessaire, pour le développement et les démonstrations.
 *   Refusé en production.
 * - « sandbox » / « test » : comptes de test pawaPay et Stripe.
 * - « live » : vrais paiements.
 */
return [
    // Durée de réservation des places pendant le paiement (minutes).
    'hold_minutes' => (int) env('ORDER_HOLD_MINUTES', 15),

    'pawapay' => [
        'mode' => env('PAWAPAY_MODE', 'simulated'), // simulated, sandbox, live
        'api_token' => env('PAWAPAY_API_TOKEN'),
        'urls' => [
            'sandbox' => 'https://api.sandbox.pawapay.io',
            'live' => 'https://api.pawapay.io',
        ],
        // Opérateurs proposés en RDC et leur code chez pawaPay.
        'operators' => [
            'vodacom' => 'VODACOM_MPESA_COD',
            'orange' => 'ORANGE_COD',
            'airtel' => 'AIRTEL_COD',
        ],
    ],

    'stripe' => [
        'mode' => env('STRIPE_MODE', 'simulated'), // simulated, test, live
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // Mode simulé : délai avant qu'un paiement mobile money soit validé « sur le téléphone ».
    // Un numéro qui finit par 0000 simule un refus (solde insuffisant).
    'simulated_delay_seconds' => (int) env('PAYMENTS_SIMULATED_DELAY', 8),
];
