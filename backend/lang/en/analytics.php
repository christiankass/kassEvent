<?php

return [
    'export' => [
        'paid_at' => 'Payment date',
        'reference' => 'Reference',
        'buyer' => 'Buyer',
        'email' => 'Email',
        'tickets' => 'Tickets',
        'details' => 'Details',
        'total' => 'Amount paid',
        'commission' => 'kassEvent fee',
        'net' => 'Net amount',
        'currency' => 'Currency',
        'method' => 'Payment method',
        'operator' => 'Operator',
        'provider_reference' => 'Payment reference',
        'source' => 'Source',
    ],
    'method' => ['mobile_money' => 'Mobile money', 'card' => 'Card', 'free' => 'Free'],
    'source' => [
        'whatsapp' => 'WhatsApp', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok',
        'linkedin' => 'LinkedIn', 'messenger' => 'Messenger', 'email' => 'Email', 'sms' => 'SMS',
        'qr' => 'QR code (poster)', 'link' => 'Copied link', 'search' => 'Google search',
        'other' => 'Other websites', 'direct' => 'Direct',
    ],
];
