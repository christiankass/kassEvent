<?php

return [
    'whatsapp_invalid' => 'Saisissez un numéro WhatsApp valide, par exemple 0812345678, ou avec l\'indicatif du pays (+33 6 12 34 56 78).',
    'not_paid' => 'Les billets ne sont pas encore émis : la commande n\'est pas payée.',
    'in_progress' => 'Un envoi est déjà en cours sur ce canal. Patientez quelques minutes.',

    // Messages Messenger (sur WhatsApp, le texte est celui des modèles approuvés par Meta).
    'intro' => '{1} Bonjour :name, voici votre billet pour « :event », :when. Présentez le QR code à l\'entrée.|[2,*] Bonjour :name, voici vos :count billets pour « :event », :when. Chaque personne présente son propre QR code à l\'entrée.',
    'intro_invitation' => '{1} Bonjour :name, voici votre invitation pour « :event », :when. Présentez le QR code à l\'entrée.|[2,*] Bonjour :name, voici vos :count invitations pour « :event », :when. Chaque invité présente son propre QR code à l\'entrée.',
    'caption' => 'Billet :type de :holder, code :code',
    'caption_invitation' => 'Invitation :type de :holder, code :code',
    'keep' => 'Chaque QR code ne permet qu\'une seule entrée : ne le partagez pas. Retrouvez vos billets à tout moment en ligne.',
    'keep_invitation' => 'Chaque QR code ne permet qu\'une seule entrée : ne le partagez pas. Retrouvez vos invitations à tout moment en ligne.',
    'view' => 'Voir mes billets',
    'view_invitation' => 'Voir mes invitations',
];
