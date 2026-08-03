<?php

declare(strict_types=1);

return [
    'actions' => [
        'assign_roles' => 'Attribuer les rôles',
        'assign_branch' => 'Affecter une agence',
        'reset_password' => 'Réinitialiser le mot de passe',
        'reset_password_heading' => 'Réinitialiser le mot de passe du compte',
        'new_password' => 'Nouveau mot de passe',
        'new_password_confirmation' => 'Confirmer le nouveau mot de passe',
        'deactivate' => 'Désactiver',
        'deactivate_confirm' => 'Le compte perdra immédiatement l’accès au panneau, même sur un onglet ouvert.',
        'reactivate' => 'Réactiver',
        'reactivate_confirm' => 'Le compte retrouvera l’accès au panneau.',
    ],
    'notifications' => [
        'roles_updated' => 'Rôles mis à jour.',
        'password_reset' => 'Mot de passe réinitialisé. L’utilisateur devra le changer avant de continuer.',
        'deactivated' => 'Compte désactivé.',
        'reactivated' => 'Compte réactivé.',
    ],
    'errors' => [
        'action_requires_staff' => 'Cette action ne peut être effectuée que par un membre du personnel connecté.',
        'role_not_assignable' => 'Vous ne pouvez pas attribuer un rôle que vous ne détenez pas vous-même.',
        'cannot_change_own_roles' => 'Vous ne pouvez pas modifier vos propres rôles.',
        'cannot_modify_super_admin' => 'Vous ne pouvez pas modifier les rôles d’un compte super_admin.',
        'cannot_deactivate_self' => 'Vous ne pouvez pas désactiver votre propre compte.',
    ],
    'activity' => [
        'roles_updated' => 'Rôles mis à jour',
        'password_reset' => 'Mot de passe réinitialisé par un responsable',
    ],
    'resources' => [
        'branch' => 'Agence',
        'branch_assignments' => 'Affectations aux agences',
    ],
];
