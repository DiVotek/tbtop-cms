<?php

declare(strict_types=1);

return [
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
    'offices' => [
        'label' => 'Offices',
        'singular' => 'office',
        'hint' => 'The first published office is the main one: its address, phones, emails, hours and coordinates are the site identity\'s; the others are published as its departments.',
        'fields' => [
            'name' => 'Name',
            'street' => 'Street address',
            'locality' => 'City',
            'region' => 'Region',
            'postal_code' => 'Postal code',
            'country' => 'Country (ISO code, e.g. UA)',
            'latitude' => 'Latitude',
            'longitude' => 'Longitude',
            'phones' => 'Phones',
            'number' => 'Number',
            'emails' => 'Emails',
            'address' => 'Address',
            'opening_hours' => 'Opening hours',
            'days' => 'Days',
            'opens' => 'Opens',
            'closes' => 'Closes',
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
