<?php

declare(strict_types=1);

return [
    'title' => 'Menus',
    'subtitle' => 'Named navigation menus. Build the item tree here; it is wired into the site separately.',
    'create_title' => 'New menu',
    'edit_title' => 'Edit menu',
    'date_format' => 'M j, Y',
    'columns' => [
        'name' => 'Name',
        'key' => 'Key',
        'updated' => 'Updated',
    ],
    'sections' => [
        'menu' => 'Menu',
        'items' => 'Items',
    ],
    'fields' => [
        'name' => 'Name',
        'key' => 'Key',
        'key_hint' => 'Auto-generated from the name. Stable identifier used to reference this menu, e.g. main or footer.',
        'key_locked_hint' => 'Fixed once the menu is created — other things reference the menu by this key.',
        'items' => 'Items',
        'children' => 'Sub-items',
        'label' => 'Label',
        'type' => 'Type',
        'page' => 'Page',
        'category' => 'Category',
        'url' => 'URL',
        'anchor' => 'Anchor',
        'anchor_hint' => 'On-page anchor, e.g. #contact.',
        'target_blank' => 'Open in a new tab',
    ],
    'types' => [
        'page' => 'Page',
        'category' => 'Category',
        'url' => 'URL',
        'anchor' => 'Anchor',
    ],
    'actions' => [
        'new' => 'New',
        'edit' => 'Edit',
        'save' => 'Save',
        'saved' => 'Menu saved',
        'delete' => 'Delete',
        'deleted' => 'Menu deleted',
        'delete_title' => 'Delete menu?',
        'delete_confirm' => 'This cannot be undone.',
    ],
    'empty' => [
        'title' => 'No menus yet',
        'description' => 'Create your first menu.',
    ],
];
