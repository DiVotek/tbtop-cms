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
    'tabs_edit' => [
        'general' => 'General',
        'seo' => 'SEO',
    ],
    'fields' => [
        'name' => 'Name',
        'title' => 'Title',
        'heading' => 'Heading',
        'summary' => 'Summary',
        'type' => 'Type',
        'parent' => 'Parent',
        'status' => 'Status',
        'sort_order' => 'Sort order',
        'is_indexable' => 'Indexable in search',
        'seo_title' => 'Meta title',
        'seo_description' => 'Meta description',
    ],
    'actions' => [
        'new' => 'New',
        'view_on_site' => 'View on site',
        'create' => 'Create',
        'cancel' => 'Cancel',
        'create_title' => 'New page',
        'created' => 'Page created',
        'save' => 'Save',
        'saved' => 'Page saved',
        'quick_edit' => 'Quick edit',
        'quick_edit_title' => 'Quick edit',
        'delete' => 'Delete',
        'delete_title' => 'Delete page?',
        'delete_confirm' => 'This cannot be undone.',
        'deleted' => 'Page deleted',
    ],
];
