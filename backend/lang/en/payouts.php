<?php

return [
    'unavailable' => [
        'no_account' => 'First tell us where to send your money (mobile money or bank account).',
        'account_pending' => 'Your payout account has just changed: for your security, it will receive payouts after the waiting period shown.',
        'in_progress' => 'A payout is already in progress in this currency. Please wait until it completes.',
        'below_minimum' => 'Your available balance has not reached the payout minimum yet.',
    ],
    'operators' => [
        'vodacom' => 'M-Pesa (Vodacom)',
        'orange' => 'Orange Money',
        'airtel' => 'Airtel Money',
    ],
    'mail' => [
        'subject' => 'Security alert: payout account changed for :organization',
        'greeting' => 'Hello,',
        'intro' => ':name has just changed the account where :organization receives its ticket money. New account: :destination.',
        'delay' => 'For your security, no payout will be sent to this account before :date.',
        'action' => 'View payouts',
        'not_you' => 'If you did not expect this change, set the right account back in kassEvent, change your password and contact us right away.',
    ],
];
