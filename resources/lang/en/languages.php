<?php

declare(strict_types=1);

return [
    'title' => 'Languages',
    'subtitle' => 'Languages available in the admin panel and on the site.',
    'tabs' => [
        'all' => 'All',
        'deleted' => 'Deleted',
    ],
    'tabs_deleted_description' => 'Languages moved to the trash — restore them or delete permanently.',
    'columns' => [
        'name' => 'Name',
        'locale' => 'Locale',
        'default' => 'Default',
        'admin' => 'Admin',
        'frontend' => 'Frontend',
        'order' => 'Order',
        'deleted_at' => 'Deleted',
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
        'is_admin_active' => 'Turning this off also removes the language from the site (Active on site is switched off too).',
        'is_frontend_active' => 'Turning this off removes the language from the site: its URLs stop responding, but its translations are kept and come back when you switch it on again.',
    ],
    'create_title' => 'New language',
    'edit_title' => 'Edit language',
    'actions' => [
        'new' => 'New',
        'create' => 'Create',
        'created' => 'Language created',
        'saved' => 'Language saved',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'deleted' => 'Language deleted',
        'delete_title' => 'Delete language?',
        'delete_confirm' => 'The language will be moved to Deleted and its URLs will stop responding on the site. Translations are kept and come back if you restore it later.',
        'restore' => 'Restore',
        'restored' => 'Language restored',
        'force_delete' => 'Delete permanently',
        'force_delete_title' => 'Delete permanently?',
        'force_delete_confirm' => 'This cannot be undone.',
        'force_deleted' => 'Language deleted permanently',
    ],
    'validation' => [
        'locale_taken' => 'A language with this locale already exists.',
        'locale_unknown' => 'Unknown locale — pick a language from the list.',
    ],
];
