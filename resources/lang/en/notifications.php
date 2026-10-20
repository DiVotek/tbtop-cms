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
        'test_subject' => 'Test email from :site',
        'test_body' => 'This is a test email from :site mail settings.',
    ],
    'bell' => [
        'title' => 'New form submission',
        'view' => 'View submission',
    ],
    'telegram' => [
        'message_title' => 'New form submission',
        'test_message' => 'This is a test message from your CMS Telegram notifications.',
    ],
    'channels' => [
        'mail' => 'Email',
        'telegram' => 'Telegram',
        'bell' => 'Bell',
    ],
    'errors' => [
        'bell' => 'Bell notification failed: :error',
        'mail' => 'Mail delivery failed: :error',
        'channel' => ':channel delivery failed: :error',
        'render' => 'Couldn\'t render the :channel notification: :error',
        'mail_no_recipients' => 'Mail notifications are enabled but no recipients are configured.',
        'telegram' => 'Telegram delivery failed: :error',
        'telegram_not_configured' => 'Telegram notifications are enabled but the bot token or chat IDs are not configured.',
    ],
];
