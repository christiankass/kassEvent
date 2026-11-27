<?php

return [
    'export' => [
        'paid_at' => 'Date du paiement',
        'reference' => 'Référence',
        'buyer' => 'Acheteur',
        'email' => 'E-mail',
        'tickets' => 'Billets',
        'details' => 'Détail',
        'total' => 'Montant payé',
        'commission' => 'Commission kassEvent',
        'net' => 'Montant net',
        'currency' => 'Devise',
        'method' => 'Moyen de paiement',
        'operator' => 'Opérateur',
        'provider_reference' => 'Référence du paiement',
        'source' => 'Source',
    ],
    'method' => ['mobile_money' => 'Mobile money', 'card' => 'Carte', 'free' => 'Gratuit'],
    'operator' => ['vodacom' => 'M-Pesa (Vodacom)', 'orange' => 'Orange Money', 'airtel' => 'Airtel Money'],
    'source' => [
        'whatsapp' => 'WhatsApp', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok',
        'linkedin' => 'LinkedIn', 'messenger' => 'Messenger', 'email' => 'E-mail', 'sms' => 'SMS',
        'qr' => 'QR code (affiche)', 'link' => 'Lien copié', 'search' => 'Moteur de recherche',
        'other' => 'Autre site', 'direct' => 'Accès direct',
    ],
];
