<?php

declare(strict_types=1);

return [
    'title' => '404 log',
    'subtitle' => 'Paths visitors requested that resolved to nothing — no page, no redirect.',
    'columns' => [
        'path' => 'Path',
        'locale' => 'Locale',
        'hits' => 'Hits',
        'last_seen_at' => 'Last seen',
        'last_referer' => 'Last referer',
        'last_user_agent' => 'Last user agent',
    ],
    'tabs' => [
        'active' => 'Active',
        'ignored' => 'Ignored',
    ],
    'empty' => [
        'title' => 'No 404s recorded',
        'description' => 'Missed paths will show up here as visitors hit them.',
    ],
    'actions' => [
        'create_redirect' => 'Create redirect',
        'create_redirect_title' => 'Create redirect',
        'create_redirect_saved' => 'Redirect created and hit marked as handled',
        'cancel' => 'Cancel',
        'ignore' => 'Ignore',
        'unignore' => 'Unignore',
        'ignored' => 'Marked as ignored',
        'unignored' => 'Marked as active',
        'delete' => 'Delete',
        'deleted' => 'Deleted',
        'delete_title' => 'Delete this row?',
        'delete_confirm' => 'This cannot be undone.',
        'bulk_ignore' => 'Ignore selected',
        'bulk_ignore_done' => 'Selected rows marked as ignored',
    ],
    'fields' => [
        'to_path' => 'To URL or path',
        'status_code' => 'Status',
    ],
];
