<?php

/*
 * Envoi des billets et invitations par WhatsApp (Cloud API de Meta) et Messenger.
 * Chaque canal a son mode :
 * - « simulated » (par défaut) : aucun compte Meta, rien ne part ; refusé en production.
 * - « live » : vrais messages (compte WhatsApp Business validé, page Facebook avec Messenger).
 * - « off » : canal non proposé aux acheteurs.
 *
 * Les deux canaux partagent l'application Meta : un seul point de notification,
 * {APP_URL}/api/v1/webhooks/meta, signé avec META_APP_SECRET.
 */
return [
    'graph_url' => 'https://graph.facebook.com/'.env('META_GRAPH_VERSION', 'v23.0'),
    'app_secret' => env('META_APP_SECRET'),
    'verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),

    'whatsapp' => [
        'mode' => env('WHATSAPP_MODE', 'simulated'), // simulated, live, off
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        // Modèles à faire approuver par Meta (catégorie « Utilité »), en fr et en : voir app/Modules/Communication/README.md.
        'templates' => [
            'ticket' => env('WHATSAPP_TEMPLATE_TICKET', 'kassevent_billet'),
            'invitation' => env('WHATSAPP_TEMPLATE_INVITATION', 'kassevent_invitation'),
        ],
    ],

    'messenger' => [
        'mode' => env('MESSENGER_MODE', 'simulated'), // simulated, live, off
        'page_token' => env('MESSENGER_PAGE_TOKEN'),
        // Nom d'utilisateur de la page Facebook (m.me/<nom>).
        'page_username' => env('MESSENGER_PAGE_USERNAME'),
    ],

    // Messages envoyés au plus par job (le job suivant reprend la suite).
    'messages_per_job' => 10,

    // Mode simulé : un numéro WhatsApp qui finit par 0000 simule « numéro sans WhatsApp ».
    'simulated_failure_suffix' => '0000',
];
