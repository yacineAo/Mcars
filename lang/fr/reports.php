<?php

declare(strict_types=1);

return [
    'resources' => [
        'report_definition' => [
            'label' => 'Rapport enregistré',
            'plural_label' => 'Rapports enregistrés',
            'sections' => [
                'scope' => 'Périmètre',
                'schedule' => 'Programmation',
            ],
            'fields' => [
                'name' => 'Nom',
                'report_type' => 'Type de rapport',
                'format' => 'Format',
                'branch' => 'Agence',
                'customer' => 'Client',
                'car' => 'Véhicule',
                'car_owner' => 'Propriétaire',
                'schedule_cron' => 'Expression Cron',
                'schedule_email' => 'Destinataire email',
                'schedule_enabled' => 'Programmation activée',
                'last_sent_at' => 'Dernier envoi',
            ],
            'help' => [
                'cron' => 'ex. "0 8 * * 1" pour chaque lundi à 8h. Format cron standard à 5 champs.',
                'email' => 'Le rapport PDF/Excel/CSV sera envoyé à cette adresse à la génération.',
            ],
            'all_branches' => 'Toutes les agences',
            'placeholder_customer' => 'Tous les clients (top liste)',
            'placeholder_car' => 'Tous les véhicules (vue flotte)',
            'never' => 'Jamais',
        ],
    ],
    'scheduled_mail' => [
        'subject' => 'Rapport programmé : :name',
        'heading' => 'Votre rapport programmé : :name',
        'body' => 'Veuillez trouver votre rapport programmé en pièce jointe.',
        'generated_at' => 'Généré le :date',
        'regards' => 'Cordialement',
    ],
];
