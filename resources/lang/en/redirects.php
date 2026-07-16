<?php

declare(strict_types=1);

return [
    'title' => 'Redirects',
    'subtitle' => 'Redirects from old URLs to new ones — created automatically on slug changes or added manually.',
    'columns' => [
        'from' => 'From',
        'to' => 'To',
        'locale' => 'Locale',
        'status' => 'Status',
        'auto' => 'Auto',
    ],
    'locale_all' => 'All locales',
    'statuses' => [
        '301' => '301 Permanent',
        '302' => '302 Temporary',
    ],
    'fields' => [
        'from_path' => 'From path',
        'to_path' => 'To URL or path',
        'locale' => 'Locale',
        'status_code' => 'Status',
    ],
    'helpers' => [
        'from_path' => 'Path without locale prefix, e.g. old-page.',
        'to_path' => 'Local path or full external URL.',
    ],
    'actions' => [
        'new' => 'New',
        'create' => 'Create',
        'cancel' => 'Cancel',
        'create_title' => 'New redirect',
        'created' => 'Redirect created',
        'edit' => 'Edit',
        'edit_title' => 'Edit redirect',
        'save' => 'Save',
        'saved' => 'Redirect saved',
        'delete' => 'Delete',
        'deleted' => 'Redirect deleted',
        'delete_title' => 'Delete redirect?',
        'delete_confirm' => 'This cannot be undone.',
    ],
    'empty' => [
        'title' => 'No redirects yet',
        'description' => 'Create your first redirect.',
    ],
    'validation' => [
        'paths_required' => 'From and to paths are required.',
        'from_not_external' => 'From path must be a local path, not an external URL.',
        'to_invalid_url' => 'External redirect target must be a valid http(s) URL with a host.',
        'to_host_not_allowlisted' => 'External redirect host is not allowlisted. Add it to tbtop-cms.redirects.allowed_external_hosts to allow it.',
        'status_invalid' => 'Status code must be 301 or 302.',
        'from_taken' => 'A redirect already exists for this path and locale.',
        'from_shadows_page' => 'A page already exists at this path.',
    ],
];
