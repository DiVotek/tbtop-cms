<?php

declare(strict_types=1);

return [
    'title' => 'Languages',
    'subtitle' => 'Languages available in the admin panel and on the site.',
    'columns' => [
        'name' => 'Name',
        'locale' => 'Locale',
        'default' => 'Default',
        'admin' => 'Admin',
        'frontend' => 'Frontend',
        'order' => 'Order',
    ],
    'empty' => [
        'title' => 'No languages yet',
        'description' => 'Add your first language.',
    ],
    'fields' => [
        'name' => 'Name',
        'locale' => 'Locale',
        'language' => 'Language',
        'is_default' => 'Default language',
        'is_admin_active' => 'Active in admin',
        'is_frontend_active' => 'Active on site',
        'sort_order' => 'Sort order',
    ],
    'helpers' => [
        'locale' => 'Locale cannot be changed after creation.',
    ],
    'create_title' => 'New language',
    'edit_title' => 'Edit language',
    'actions' => [
        'new' => 'New',
        'create' => 'Create',
        'cancel' => 'Cancel',
        'created' => 'Language created',
        'saved' => 'Language saved',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'deleted' => 'Language deleted',
        'delete_title' => 'Delete language?',
        'delete_confirm' => 'This cannot be undone.',
    ],
    'validation' => [
        'locale_taken' => 'A language with this locale already exists.',
        'locale_unknown' => 'Unknown locale — pick a language from the list.',
    ],
];
