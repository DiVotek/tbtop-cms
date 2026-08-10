<?php

declare(strict_types=1);

return [
    'title' => 'Sign in',
    'subtitle' => 'Sign in to the control panel',
    'fields' => [
        'email' => 'Email',
        'password' => 'Password',
        'remember' => 'Remember me',
    ],
    'actions' => [
        'sign_in' => 'Sign in',
    ],
    'errors' => [
        'failed' => 'These credentials do not match our records.',
        'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    ],
    'links' => [
        'site' => 'Go to site',
    ],
];
