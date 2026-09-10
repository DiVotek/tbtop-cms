<?php

declare(strict_types=1);

return [
    'invalid' => 'This link is not valid.',
    'types' => [
        'page' => 'Page',
        'external' => 'URL',
        'email' => 'Email',
        'phone' => 'Phone',
    ],
    'anchor' => 'Anchor',
    'anchor_placeholder' => 'section-id',
    'page_placeholder' => 'Search pages…',
    'placeholders' => [
        'external' => 'https://example.com',
        'email' => 'name@example.com',
        'phone' => '+380 44 123 45 67',
    ],
    'text' => 'Text',
    'errors' => [
        'external' => 'Enter an http(s) URL, or a viber:/tg:/whatsapp:/skype:/sms: link.',
        'email' => 'Enter a valid email address.',
        'phone' => 'Enter 6 to 20 digits, optionally starting with +.',
        'anchor' => 'Use letters, numbers, dashes and underscores only — no #.',
        'label' => 'Keep the link text under 255 characters.',
        'page' => 'Pick a page.',
    ],
];
