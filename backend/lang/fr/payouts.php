<?php

return [
    'unavailable' => [
        'no_account' => 'Indiquez d\'abord où recevoir votre argent (mobile money ou compte bancaire).',
        'account_pending' => 'Votre compte de reversement vient de changer : par sécurité, il recevra des reversements à la fin du délai indiqué.',
        'in_progress' => 'Un reversement est déjà en cours dans cette devise. Patientez jusqu\'à sa fin.',
        'below_minimum' => 'Le solde disponible n\'atteint pas encore le minimum de reversement.',
    ],
    'operators' => [
        'vodacom' => 'M-Pesa (Vodacom)',
        'orange' => 'Orange Money',
        'airtel' => 'Airtel Money',
    ],
    'mail' => [
        'subject' => 'Alerte de sécurité : compte de reversement modifié pour :organization',
        'greeting' => 'Bonjour,',
        'intro' => ':name vient de changer le compte où :organization reçoit l\'argent de ses billets. Nouveau compte : :destination.',
        'delay' => 'Par sécurité, aucun reversement ne partira vers ce compte avant le :date.',
        'action' => 'Voir les versements',
        'not_you' => 'Si ce changement n\'est pas prévu, remettez le bon compte depuis kassEvent, changez votre mot de passe et prévenez-nous sans attendre.',
    ],
];
