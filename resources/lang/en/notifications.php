<?php

declare(strict_types=1);

return [
    'mail' => [
        'subject' => 'New form submission',
        'fields' => [
            'page' => 'Page',
            'locale' => 'Locale',
            'submitted_at' => 'Submitted',
        ],
        'view_submission' => 'View submission',
    ],
    'bell' => [
        'title' => 'New form submission',
        'view' => 'View submission',
    ],
    'telegram' => [
        'message_title' => 'New form submission',
        'test_message' => 'This is a test message from your CMS Telegram notifications.',
    ],
    'errors' => [
        'bell' => 'Bell notification failed: :error',
        'mail' => 'Mail delivery failed: :error',
        'telegram' => 'Telegram delivery failed: :error',
    ],
];
