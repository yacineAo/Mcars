<?php

declare(strict_types=1);

return [
    'help' => [
        'code' => 'Figure dans chaque numéro de document émis par cette agence (ex. CTR-MAIN-2026-000123). Jusqu’à 8 caractères.',
        'manager' => 'Qui dirige cette agence au quotidien.',
    ],
    'columns' => [
        'manager' => 'Responsable',
        'users' => 'Personnel',
        'default_badge' => 'Par défaut',
    ],
    'actions' => [
        'make_default' => 'Définir par défaut',
        'make_default_confirm' => 'Cette agence devient l’unique agence par défaut. Les numéros de documents gardent leur préfixe (le code) ; seule la résolution du branch_id nul change.',
        'deactivate' => 'Désactiver',
        'deactivate_confirm' => 'Cette agence ne sera plus sélectionnable. Son historique reste là où il a été enregistré.',
        'reactivate' => 'Réactiver',
        'reactivate_confirm' => 'Cette agence redevient sélectionnable.',
        'delete' => 'Supprimer',
        'delete_heading' => 'Supprimer cette agence ?',
        'delete_confirm' => 'L’agence est supprimée en mode logiciel. Elle ne peut pas être retirée tant qu’une transaction, une réservation ou toute autre ligne la référence.',
    ],
    'notifications' => [
        'made_default' => 'Agence par défaut mise à jour.',
        'deactivated' => 'Agence désactivée.',
        'reactivated' => 'Agence réactivée.',
        'deleted' => 'Agence supprimée.',
    ],
    'validation' => [
        'code_unique' => 'Une agence portant ce code existe déjà. Les codes sont uniques, sans tenir compte de la casse.',
    ],
    'errors' => [
        'action_requires_staff' => 'Cette action ne peut être effectuée que par un membre du personnel connecté.',
        'cannot_default_inactive' => 'Impossible de définir par défaut une agence désactivée.',
        'cannot_deactivate_default' => 'L’agence par défaut ne peut pas être désactivée.',
        'cannot_delete_default' => 'L’agence par défaut ne peut pas être supprimée.',
        'has_dependent_rows' => 'Cette agence a encore des lignes qui la référencent. Déplacez-les ou clôturez-les avant de la supprimer.',
    ],
    'activity' => [
        'made_default' => 'Agence définie par défaut',
    ],
];
