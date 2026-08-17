<?php

declare(strict_types=1);

return [
    'title' => 'SEO report',
    'subtitle' => 'Site-wide SEO issues across every published page and active locale.',
    'columns' => [
        'severity' => 'Severity',
        'issue' => 'Issue',
        'page' => 'Page',
        'locale' => 'Locale',
        'detail' => 'Detail',
    ],
    'severities' => [
        'warn' => 'Warning',
        'fail' => 'Fail',
    ],
    'empty' => [
        'title' => 'No issues found',
        'description' => 'Every published page passed every check.',
    ],
    'summary' => [
        'clean' => 'No SEO issues found across published pages.',
        'problems' => ':count problem(s) on :pages page(s).',
    ],
    'page' => [
        'sqlite_missing' => 'The table view needs the sqlite PHP extension (pdo_sqlite), which this server does not have — showing the plain report instead. Install pdo_sqlite to get the full table.',
    ],
    'untitled_page' => 'Untitled page #:id',
    'issues' => [
        'missing_title' => [
            'label' => 'Missing title',
            'detail' => 'Neither an SEO title nor the page\'s own title/name is set for this locale.',
        ],
        'missing_description' => [
            'label' => 'Missing description',
            'detail' => 'Neither an SEO description nor the page\'s own summary is set for this locale.',
        ],
        'duplicate_title' => [
            'label' => 'Duplicate title',
            'detail' => 'Same title as: :pages.',
        ],
        'missing_og_image' => [
            'label' => 'Missing OG image',
            'detail' => 'No OG image resolves for this page — not on the page, and no site-wide default is configured.',
        ],
        'noindex' => [
            'label' => 'Noindex',
            'detail' => 'This published page is set to noindex. Shown here for visibility — it may be intentional.',
        ],
        'missing_translation' => [
            'label' => 'Missing translation',
            'detail' => 'No title and no name set for this locale — a hreflang gap for active crawlers.',
        ],
    ],
];
