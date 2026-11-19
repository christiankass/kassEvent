<?php

return [
    'roles' => [
        'owner' => 'owner',
        'admin' => 'administrator',
        'event_manager' => 'event manager',
        'finance' => 'finance',
        'check_in_agent' => 'check-in agent',
        'viewer' => 'read only',
    ],
    'mail' => [
        'subject' => ':inviter invites you to join :organization on kassEvent',
        'greeting' => 'Hello,',
        'intro' => ':inviter invites you to join :organization on kassEvent as :role.',
        'action' => 'View the invitation',
        'expires' => 'This link is valid until :date.',
        'ignore' => 'If you were not expecting this invitation, you can ignore this email.',
    ],
    'already_member' => 'This person is already a member of the organization.',
    'not_yourself' => 'You cannot change your own access.',
    'owner_locked' => 'The owner\'s access cannot be changed.',
    'expired' => 'This invitation has expired.',
    'wrong_account' => 'This invitation was sent to :email. Sign in with that account to accept it.',
    'renewed' => 'A new link has just been sent to :email.',
];
