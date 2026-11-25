<?php

return [
    'invitation' => 'Invitation',
    'too_many' => 'Une liste peut compter au plus :max invités.',
    'closed' => 'L\'événement a commencé : les réponses sont closes.',
    'table_full' => ':table n\'a plus que :left place(s) libre(s) ; cet invité en occupe :party.',
    'table_too_small' => ':table accueille déjà :count personne(s) : retirez des invités avant de réduire ses places.',
    'declined_not_seated' => 'Cet invité ne vient pas : il ne peut pas être placé.',
    'too_few_tables' => 'Trop peu de tables : au plus :max places par table.',
    'too_many_tables' => 'Trop de tables : au plus :max. Augmentez les places par table.',
    'mail' => [
        'subject' => 'Vous êtes invité(e) : :event',
        'greeting' => 'Bonjour :name,',
        'intro' => '{0} vous êtes invité(e) à « :event ». Dites-nous si vous venez.|{1} vous êtes invité(e) à « :event », avec un accompagnant. Dites-nous si vous venez.|[2,*] vous êtes invité(e) à « :event », avec jusqu\'à :count accompagnants. Dites-nous si vous venez.',
        'answer' => 'Répondre à l\'invitation',
        'personal' => 'Ce lien est personnel. Une fois votre venue confirmée, vous recevez votre invitation avec QR code, à présenter à l\'entrée.',
    ],
];
