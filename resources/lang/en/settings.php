<?php

declare(strict_types=1);

return [
    'common' => [
        'save' => 'Save',
        'saved' => 'Saved',
    ],
    'site' => [
        'title' => 'Site settings',
        'subtitle' => 'General site identity: name, logo, and favicon.',
        'fields' => [
            'site_name' => 'Site name',
            'logo' => 'Logo',
            'favicon' => 'Favicon',
        ],
    ],
    'seo' => [
        'title' => 'SEO settings',
        'subtitle' => 'Default metadata used across the site unless a page overrides it.',
        'fields' => [
            'title_suffix' => 'Title suffix',
            'default_description' => 'Default description',
            'default_og_image' => 'Default OG image',
            'indexing_enabled' => 'Allow search engines to index this site',
        ],
        'placeholders' => [
            'title_suffix' => ' — Site Name',
        ],
    ],
    'notifications' => [
        'title' => 'Notification settings',
        'subtitle' => 'Outgoing mail notifications and SMTP credentials.',
        'fields' => [
            'mail_enabled' => 'Mail enabled',
            'recipients' => 'Recipients',
            'smtp_password' => 'SMTP password',
        ],
        'helpers' => [
            'recipients' => 'One email address per line.',
            'smtp_password' => 'Leave blank to keep the current value.',
        ],
    ],
];
