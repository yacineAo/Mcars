<?php

declare(strict_types=1);

return [

    'actions' => [
        'view' => 'Consulter',
    ],

    'mail' => [
        'signature' => ':app — alerte automatique',
    ],

    'fields' => [
        'reference' => 'Référence',
        'customer' => 'Client',
        'owner' => 'Propriétaire',
        'car' => 'Véhicule',
        'due_at' => 'Échéance',
        'due_date' => "Date d'échéance",
        'hours_late' => 'Heures de retard',
        'days_late' => 'Jours de retard',
        'days_remaining' => 'Jours restants',
        'amount' => 'Montant (DZD)',
        'sequence' => 'Échéance n°',
        'document_type' => 'Document',
        'number' => 'Numéro',
        'expiry_date' => 'Expire le',
        'licence_number' => 'N° de permis',
        'task' => 'Intervention',
        'due_odometer' => 'Échéance (km)',
        'current_odometer' => 'Actuel (km)',
        'km_remaining' => 'Km restants',
        'category' => 'Catégorie',
        'description' => 'Description',
        'account' => 'Compte',
        'closed_at' => 'Clôturée le',
        'closed_by' => 'Clôturée par',
        'counted' => 'Compté (DZD)',
        'url' => 'Lien',
    ],

    'alerts' => [
        'booking_return_due' => [
            'subject' => 'Retour prévu : :reference',
            'body' => 'La location :reference de :customer (:car) doit être restituée le :due_at.',
        ],
        'booking_overdue' => [
            'subject' => 'Location en retard : :reference',
            'body' => ":customer n'a pas restitué :car. Le retour était prévu le :due_at, il y a :hours_late heure(s).",
        ],
        'customer_payment_overdue' => [
            'subject' => 'Paiement en retard : :customer',
            'body' => 'Un paiement de :amount DZD de :customer était dû le :due_date, il y a :days_late jour(s).',
        ],
        'owner_installment_due' => [
            'subject' => 'Échéance propriétaire : :owner',
            'body' => "L'échéance :sequence de :amount DZD pour :car (propriétaire :owner) est due le :due_date.",
        ],
        'car_document_expiring' => [
            'subject' => ':document_type expire : :car',
            'body' => 'Le document :document_type de :car (n° :number) expire le :expiry_date — :days_remaining jour(s) restant(s).',
        ],
        'driving_licence_expiring' => [
            'subject' => 'Permis de conduire expire : :customer',
            'body' => 'Le permis de :customer (n° :licence_number) expire le :expiry_date — :days_remaining jour(s) restant(s).',
        ],
        'maintenance_due' => [
            'subject' => 'Entretien à prévoir : :car',
            'body' => ':task est à effectuer sur :car le :due_date ou à :due_odometer km (actuellement :current_odometer km).',
        ],
        'recurring_expense_due' => [
            'subject' => 'Charge récurrente à payer : :category',
            'body' => ':description (:category), :amount DZD, est due le :due_date.',
        ],
        'cash_variance' => [
            'subject' => 'Écart de caisse sur :account',
            'body' => 'La session de caisse :account clôturée par :closed_by le :closed_at ne correspond pas au grand livre. Compté : :counted DZD.',
        ],
        'backup_failed' => [
            'subject' => 'Échec de la sauvegarde',
            'body' => "La sauvegarde planifiée n'a pas abouti. Vérifiez le journal de sauvegarde immédiatement.",
        ],
    ],

    'digest' => [
        'subject' => 'Votre résumé quotidien — :count alerte(s), :date',
        'heading' => 'Résumé quotidien des alertes',
        'intro' => 'Vous avez :count alerte(s) au cours des dernières 24 heures.',
        'footer' => 'Vous recevez un résumé quotidien au lieu des e-mails individuels. Modifiable dans votre profil.',
    ],

    'resources' => [
        'alert_rule' => [
            'label' => "Règle d'alerte",
            'plural_label' => "Règles d'alerte",
            'global' => 'Toutes les agences',
            'once' => 'Une seule fois',
            'sections' => [
                'what' => 'Quoi surveiller',
                'when' => 'Quand déclencher',
                'who' => 'Qui prévenir',
            ],
            'fields' => [
                'type' => "Type d'alerte",
                'branch' => 'Agence',
                'template_key' => 'Clé de modèle',
                'days_before' => 'Anticipation (jours)',
                'repeat_every_days' => 'Répéter tous les (jours)',
                'max_repeats' => 'Répétitions max',
                'channels' => 'Canaux',
                'recipient_roles' => 'Destinataires',
                'is_active' => 'Active',
            ],
            'help' => [
                'branch' => "Laisser vide pour toutes les agences. Une règle d'agence remplace la règle globale.",
                'template_key' => "Clé de traduction sous notifications.*. La modifier repart d'un historique de déduplication vierge.",
                'timing' => 'Les répétitions évitent la lassitude : une assurance expirant dans 30 jours doit produire quelques alertes, pas trente.',
                'days_before' => "Déclenche autant de jours avant l'échéance. 0 réagit le jour même.",
                'repeat_every_days' => 'Laisser vide pour alerter une seule fois par sujet.',
                'max_repeats' => 'Laisser vide pour aucun plafond.',
                'recipient_roles' => 'Propriétaire et client ne visent que la personne concernée — jamais tous les titulaires du rôle.',
            ],
        ],
        'notification_log' => [
            'label' => 'Journal de diffusion',
            'plural_label' => 'Journal de diffusion',
            'sections' => [
                'delivery' => 'Diffusion',
                'content' => 'Contenu',
            ],
            'fields' => [
                'created_at' => 'Levée le',
                'type' => 'Alerte',
                'channel' => 'Canal',
                'recipient' => 'Destinataire',
                'status' => 'Statut',
                'attempts' => 'Tentatives',
                'subject' => 'Concerne',
                'branch' => 'Agence',
                'payload' => 'Données',
                'error' => 'Erreur',
            ],
            'filters' => [
                'failed_only' => 'Échecs uniquement',
            ],
        ],
    ],

    'preferences' => [
        'title' => 'Préférences de notification',
        'digest' => 'Résumé quotidien au lieu des e-mails individuels',
        'digest_help' => "Un seul e-mail de synthèse par jour. La cloche in-app n'est pas affectée.",
        'digest_at' => 'Envoyer le résumé à',
        'saved' => 'Préférences enregistrées.',
    ],

];
