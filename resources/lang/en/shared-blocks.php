<?php

declare(strict_types=1);

return [
    'title' => 'Shared blocks',
    'subtitle' => 'A block edited once and placed on any number of pages.',
    'edit_title' => 'Edit shared block: :name',
    'fields' => [
        'name' => 'Name',
        'kind' => 'Block type',
    ],
    'columns' => [
        'name' => 'Name',
        'kind' => 'Block type',
        'usage' => 'Used on',
    ],
    'usage_count' => '{0} Not placed on any page|{1} Placed on :count page|[2,*] Placed on :count pages',
    'usage_title' => 'Where it is placed',
    'tabs' => [
        'all' => 'All',
    ],
    'choice' => [
        'title' => 'Add «:label»',
        'description' => 'This kind has shared blocks. Place one by reference — it is edited in one place for every page — or add a fresh block with its own content.',
        'add_inline' => 'Add a new one',
    ],
    'empty' => [
        'title' => 'No shared blocks yet',
        'description' => 'Create one, fill it in, then place it on pages from the "Add block" tab.',
    ],
    'actions' => [
        'new' => 'New shared block',
        'create' => 'Create',
        'create_title' => 'New shared block',
        'edit' => 'Edit',
        'save' => 'Save',
        'saved' => 'Shared block saved',
        'delete' => 'Delete',
        'delete_title' => 'Delete shared block?',
        'delete_confirm' => 'The block is placed on no page; this cannot be undone.',
        'deleted' => 'Shared block deleted',
    ],
    'share' => [
        'action' => 'Make shared',
        'title' => 'Make this block shared?',
        'confirm' => 'Its current content becomes a shared block named after this page; this tab turns into a reference you can place on other pages. Rename it on the shared block page.',
        'done' => 'Shared block «:name» created — this block now references it',
        'missing' => 'The block is no longer on this page.',
    ],
    'reference' => [
        'info' => 'This block shows the shared block ":name". Edit the content there — every page that places it changes together.',
        'missing' => 'Shared block #:id no longer exists; this block renders a placeholder. Remove it or detach it.',
        'edit_source' => 'Edit shared block',
        'detach' => 'Detach',
        'detach_title' => 'Detach from the shared block?',
        'detach_confirm' => 'The current content of ":name" is copied into this page. Later edits to the shared block will no longer reach this page.',
        'detached' => 'Block detached — it now has its own content',
    ],
];
