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
        'subtitle' => 'Mail transport for the site and the recipients notified about new form submissions.',
        'sections' => [
            'notifications' => 'Notifications',
            'mail_transport' => 'Mail transport',
        ],
        'fields' => [
            'mail_enabled' => 'Mail enabled',
            'recipients' => 'Recipients',
            'mail_host' => 'SMTP host',
            'mail_port' => 'SMTP port',
            'mail_username' => 'SMTP username',
            'mail_password' => 'SMTP password',
            'mail_encryption' => 'Encryption',
            'mail_from_address' => 'From address',
            'mail_from_name' => 'From name',
        ],
        'helpers' => [
            'recipients' => 'One email address per line, or separated by commas.',
            'mail_password' => 'Leave blank to keep the current value.',
        ],
        'encryption' => [
            'none' => 'None',
            'tls' => 'TLS',
            'ssl' => 'SSL',
        ],
        'actions' => [
            'send_test_email' => 'Send test email',
            'test_email_sent' => 'Test email sent.',
            'test_email_no_recipients' => 'Add at least one recipient before sending a test email.',
            'test_email_failed' => 'Could not send the test email: :error',
        ],
    ],
];
