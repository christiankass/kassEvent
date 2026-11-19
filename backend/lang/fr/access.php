<?php

return [
    'roles' => [
        'owner' => 'propriétaire',
        'admin' => 'administrateur',
        'event_manager' => 'gestionnaire d\'événements',
        'finance' => 'finance',
        'check_in_agent' => 'agent d\'accueil',
        'viewer' => 'lecture seule',
    ],
    'mail' => [
        'subject' => ':inviter vous invite à rejoindre :organization sur kassEvent',
        'greeting' => 'Bonjour,',
        'intro' => ':inviter vous invite à rejoindre :organization sur kassEvent, avec le rôle :role.',
        'action' => 'Voir l\'invitation',
        'expires' => 'Ce lien est valable jusqu\'au :date.',
        'ignore' => 'Si vous ne vous attendiez pas à cette invitation, vous pouvez ignorer cet e-mail.',
    ],
    'already_member' => 'Cette personne fait déjà partie de l\'organisation.',
    'not_yourself' => 'Vous ne pouvez pas modifier votre propre accès.',
    'owner_locked' => 'L\'accès du propriétaire ne peut pas être modifié.',
    'expired' => 'Cette invitation a expiré.',
    'wrong_account' => 'Cette invitation a été envoyée à :email. Connectez-vous avec ce compte pour l\'accepter.',
    'renewed' => 'Un nouveau lien vient d\'être envoyé à :email.',
];
