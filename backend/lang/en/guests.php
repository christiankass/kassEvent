<?php

return [
    'invitation' => 'Invitation',
    'too_many' => 'A guest list can hold at most :max guests.',
    'closed' => 'The event has started: replies are closed.',
    'table_full' => ':table only has :left free seat(s) left; this guest takes :party.',
    'table_too_small' => ':table already seats :count person(s): move guests away before reducing its seats.',
    'declined_not_seated' => 'This guest is not coming: they cannot be seated.',
    'too_few_tables' => 'Too few tables: at most :max seats per table.',
    'too_many_tables' => 'Too many tables: at most :max. Increase the seats per table.',
    'mail' => [
        'subject' => 'You are invited: :event',
        'greeting' => 'Hello :name,',
        'intro' => '{0} you are invited to “:event”. Let us know if you are coming.|{1} you are invited to “:event”, with one companion. Let us know if you are coming.|[2,*] you are invited to “:event”, with up to :count companions. Let us know if you are coming.',
        'answer' => 'Reply to the invitation',
        'personal' => 'This link is personal. Once you confirm, you receive your invitation with a QR code to show at the entrance.',
    ],
];
