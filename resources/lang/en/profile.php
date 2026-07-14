<?php

declare(strict_types=1);

return [
    'title' => 'Profile',
    'subtitle' => 'Your personal details and admin language.',
    'sections' => [
        'personal' => 'Personal info',
        'password' => 'Change password',
        'preferences' => 'Preferences',
    ],
    'fields' => [
        'display_name' => 'Display name',
        'email' => 'Email',
        'locale' => 'Language',
        'current_password' => 'Current password',
        'new_password' => 'New password',
        'confirm_password' => 'Confirm new password',
    ],
    'actions' => [
        'save' => 'Save',
        'saved' => 'Saved',
        'update_password' => 'Update password',
        'password_updated' => 'Password updated.',
    ],
];
