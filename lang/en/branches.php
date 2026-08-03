<?php

declare(strict_types=1);

return [
    'help' => [
        'code' => 'Appears in every document number issued by this office (e.g. CTR-MAIN-2026-000123). Up to 8 characters.',
        'manager' => 'Who runs this office day to day.',
    ],
    'columns' => [
        'manager' => 'Manager',
        'users' => 'Staff',
        'default_badge' => 'Default',
    ],
    'actions' => [
        'make_default' => 'Make default',
        'make_default_confirm' => 'This office becomes the single default branch. Document numbers keep their own prefix (the code), only resolution of null branch_id changes.',
        'deactivate' => 'Deactivate',
        'deactivate_confirm' => 'This office stops being selectable. Its history stays where it was recorded.',
        'reactivate' => 'Reactivate',
        'reactivate_confirm' => 'This office becomes selectable again.',
        'delete' => 'Delete',
        'delete_heading' => 'Delete this branch?',
        'delete_confirm' => 'The branch is soft-deleted. It cannot be removed while any transaction, booking or other row still points at it.',
    ],
    'notifications' => [
        'made_default' => 'Default branch updated.',
        'deactivated' => 'Branch deactivated.',
        'reactivated' => 'Branch reactivated.',
        'deleted' => 'Branch deleted.',
    ],
    'validation' => [
        'code_unique' => 'A branch with this code already exists. Codes are unique, case-insensitively.',
    ],
    'errors' => [
        'action_requires_staff' => 'This action can only be performed by a signed-in staff member.',
        'cannot_default_inactive' => 'Cannot make an inactive branch the default branch.',
        'cannot_deactivate_default' => 'The default branch cannot be deactivated.',
        'cannot_delete_default' => 'The default branch cannot be deleted.',
        'has_dependent_rows' => 'This branch still has rows pointing at it. Move or close them before deleting it.',
    ],
    'activity' => [
        'made_default' => 'Made this branch the default',
    ],
];
