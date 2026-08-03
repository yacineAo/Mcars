<?php

declare(strict_types=1);

return [
    'actions' => [
        'assign_roles' => 'Assign roles',
        'assign_branch' => 'Assign a branch',
        'reset_password' => 'Reset password',
        'reset_password_heading' => 'Reset the account password',
        'new_password' => 'New password',
        'new_password_confirmation' => 'Confirm new password',
        'deactivate' => 'Deactivate',
        'deactivate_confirm' => 'The account will lose panel access immediately, even for an open tab.',
        'reactivate' => 'Reactivate',
        'reactivate_confirm' => 'The account will regain panel access.',
    ],
    'notifications' => [
        'roles_updated' => 'Roles updated.',
        'password_reset' => 'Password reset. The user must change it before continuing.',
        'deactivated' => 'Account deactivated.',
        'reactivated' => 'Account reactivated.',
    ],
    'errors' => [
        'action_requires_staff' => 'This action can only be performed by a signed-in staff member.',
        'role_not_assignable' => 'You cannot assign a role you do not hold yourself.',
        'cannot_change_own_roles' => 'You cannot change your own roles.',
        'cannot_modify_super_admin' => 'You cannot change the roles of a super_admin account.',
        'cannot_deactivate_self' => 'You cannot deactivate your own account.',
    ],
    'activity' => [
        'roles_updated' => 'Roles updated',
        'password_reset' => 'Password reset by a manager',
    ],
    'resources' => [
        'branch' => 'Branch',
        'branch_assignments' => 'Branch assignments',
    ],
];
