<?php

declare(strict_types=1);

return [
    'title' => 'Users',
    'subtitle' => 'Grant, revoke, and change the cms access role of existing users.',
    'columns' => [
        'name' => 'Name',
        'email' => 'Email',
        'access' => 'CMS access',
    ],
    'no_access' => 'No access',
    'roles' => [
        'superadministrator' => 'Superadministrator',
        'administrator' => 'Administrator',
        'editor' => 'Editor',
    ],
    'fields' => [
        'role' => 'Role',
    ],
    'actions' => [
        'grant' => 'Grant access',
        'grant_title' => 'Grant CMS access',
        'granted' => 'Access granted',
        'change_role' => 'Change role',
        'change_role_title' => 'Change role',
        'role_changed' => 'Role updated',
        'revoke' => 'Revoke access',
        'revoke_title' => 'Revoke CMS access?',
        'revoke_confirm' => 'This user will lose access to the CMS admin. This cannot be undone from here.',
        'revoked' => 'Access revoked',
        'save' => 'Save',
        'cancel' => 'Cancel',
    ],
    'empty' => 'No users found.',
    'validation' => [
        'cannot_manage_self' => 'You cannot change your own access or role.',
        'already_has_access' => 'This user already has CMS access.',
        'no_access' => 'This user has no CMS access to change.',
        'cannot_manage_role' => 'You are not allowed to manage a user with this role.',
        'cannot_assign_role' => 'You are not allowed to assign this role.',
    ],
];
