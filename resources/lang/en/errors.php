<?php

declare(strict_types=1);

return [
    // Used only when the DB page is missing (e.g. seeder not run yet) — the
    // package's last-resort heading, independent of whatever an editor has
    // typed into the seeded page's own `heading` field.
    '4xx' => [
        'heading' => 'Page not found',
    ],
    '5xx' => [
        'heading' => 'Something went wrong',
    ],
];
