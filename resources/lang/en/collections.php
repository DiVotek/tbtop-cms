<?php

declare(strict_types=1);

return [
    'title' => 'Collections',
    'columns' => [
        'title' => 'Name',
        'status' => 'Status',
        'updated_at' => 'Updated',
    ],
    'status' => [
        'draft' => 'Draft',
        'published' => 'Published',
    ],
    'fields' => [
        'status' => 'Status',
    ],
    'empty' => [
        'title' => 'No entries yet',
        'description' => 'Add the first one; blocks show the published entries.',
    ],
    'actions' => [
        'new' => 'Add :name',
        'create' => 'Create',
        'edit' => 'Edit',
        'save' => 'Save',
        'saved' => 'Saved',
        'delete' => 'Delete',
        'delete_title' => 'Delete this entry?',
        'delete_confirm' => 'Blocks stop showing it; this cannot be undone.',
        'deleted' => 'Deleted',
    ],
    'edit_title' => ':collection: :name',
    'conflict' => 'This entry was changed by someone else while you were editing, so your unsaved edits were not applied — the form now shows the latest version.',
    'field' => [
        'invalid' => 'This selection is not valid.',
        'modes' => [
            'all' => 'All',
            'latest' => 'Latest',
            'related' => 'Related to the page',
            'manual' => 'Pick by hand',
        ],
        'limit' => 'How many',
        'search' => 'Search…',
        'empty' => 'Nothing found.',
        'add' => 'Add',
        'remove' => 'Remove',
    ],
    'phones' => [
        'label' => 'Phones',
        'singular' => 'phone',
        'fields' => [
            'number' => 'Number',
        ],
    ],
    'emails' => [
        'label' => 'Emails',
        'singular' => 'email',
        'fields' => [
            'address' => 'Address',
        ],
    ],
    'socials' => [
        'label' => 'Social links',
        'singular' => 'social link',
        'fields' => [
            'label' => 'Short label',
            'name' => 'Name',
            'href' => 'Link',
        ],
    ],
];
