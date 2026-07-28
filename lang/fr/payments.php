<?php

declare(strict_types=1);

return [
    'actions' => [
        'post' => 'Comptabiliser',
    ],
    'notifications' => [
        'posted' => 'Paiement enregistré et comptabilisé.',
        'post_failed' => "Le paiement a été enregistré mais N'A PAS pu être comptabilisé",
    ],
];
