<?php

declare(strict_types=1);

return [
    'title' => 'Users',
    'subtitle' => 'Grant and revoke cms access for existing users.',
    'columns' => [
        'name' => 'Name',
        'email' => 'Email',
        'access' => 'CMS access',
    ],
    'access' => [
        'granted' => 'Has access',
        'none' => 'No access',
    ],
    'actions' => [
        'grant' => 'Grant access',
        'grant_title' => 'Grant CMS access?',
        'grant_confirm' => 'This user will be able to sign in to the CMS admin.',
        'granted' => 'Access granted',
        'revoke' => 'Revoke access',
        'revoke_title' => 'Revoke CMS access?',
        'revoke_confirm' => 'This user will lose access to the CMS admin. This cannot be undone from here.',
        'revoked' => 'Access revoked',
    ],
    'empty' => 'No users found.',
    'validation' => [
        'cannot_manage_self' => 'You cannot change your own access.',
        'already_has_access' => 'This user already has CMS access.',
        'no_access' => 'This user has no CMS access to revoke.',
    ],
];
