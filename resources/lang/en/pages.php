<?php

declare(strict_types=1);

return [
    'title' => 'Content',
    'tab_description' => ':tab — categories group content, pages stand alone, articles live under a category.',
    'tabs' => [
        'all' => 'All',
        'categories' => 'Categories',
        'pages' => 'Pages',
        'articles' => 'Articles',
    ],
    'columns' => [
        'title' => 'Title',
        'type' => 'Type',
        'parent' => 'Parent',
        'status' => 'Status',
        'updated' => 'Updated',
    ],
    'status' => [
        'published' => 'Published',
        'draft' => 'Draft',
    ],
    'types' => [
        'page' => 'Page',
        'category' => 'Category',
        'article' => 'Article',
    ],
    'date_format' => 'n/j/Y',
    'empty' => 'Nothing here yet.',
    'actions' => [
        'new' => 'New',
        'view_on_site' => 'View on site',
        'create' => 'Create',
        'cancel' => 'Cancel',
        'create_title' => 'New page',
        'edit_title' => 'Edit page',
        'created' => 'Page created',
    ],
];
