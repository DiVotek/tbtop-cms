<?php

declare(strict_types=1);

return [
    'title' => 'Layouts',
    'subtitle' => 'Header and footer blocks shared across every page.',
    'slots' => [
        'header' => 'Header',
        'footer' => 'Footer',
    ],
    'slots_description' => [
        'header' => 'Blocks rendered above the page content on every page.',
        'footer' => 'Blocks rendered below the page content on every page.',
    ],
    'blocks' => [
        'inactive_badge' => 'Off',
    ],
    'blocks_count' => ':count block|:count blocks',
    'updated_at' => 'Updated :time',
    'empty' => 'No blocks yet',
    'actions' => [
        'edit' => 'Edit',
        'save' => 'Save',
        'saved' => 'Layout saved',
    ],
    'edit_title' => 'Edit :slot layout',
];
