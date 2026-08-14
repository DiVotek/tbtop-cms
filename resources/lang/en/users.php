<?php

declare(strict_types=1);

return [
    'title' => 'Users',
    'subtitle' => 'Create, edit, and grant or revoke cms access for users.',
    'columns' => [
        'name' => 'Name',
        'email' => 'Email',
        'access' => 'CMS access',
    ],
    'access' => [
        'granted' => 'Has access',
        'owner' => 'Owner',
        'none' => 'No access',
    ],
    'fields' => [
        'name' => 'Name',
        'email' => 'Email',
        'password' => 'Password',
    ],
    'helpers' => [
        'name_defaults_to_email' => 'Leave blank to use the email address as the name.',
        'password_blank_keeps' => 'Leave blank to keep the current password.',
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
        'new' => 'New user',
        'create' => 'Create user',
        'create_title' => 'Create user',
        'created' => 'User created',
        'edit' => 'Edit',
        'edit_title' => 'Edit user',
        'save' => 'Save',
        'saved' => 'User updated',
    ],
    'empty' => [
        'title' => 'No users found',
        'description' => 'This table lists your host app users. Once one exists, grant it CMS access to let it sign in to the admin.',
    ],
    'validation' => [
        'cannot_manage_self' => 'You cannot change your own access.',
        'already_has_access' => 'This user already has CMS access.',
        'no_access' => 'This user has no CMS access to revoke.',
        'cannot_revoke_owner' => 'The owner cannot be revoked.',
        'cannot_edit_owner' => 'Only the owner can edit their own account.',
        'name_required' => 'Enter a name.',
        'email_invalid' => 'Enter a valid email address.',
        'email_exists' => 'A user with this email already exists. Use Grant access instead.',
        'email_taken' => 'Another user already uses this email address.',
        'password_too_short' => 'The password must be at least 8 characters.',
    ],
];
