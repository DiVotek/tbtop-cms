<?php

declare(strict_types=1);

return [
    'title' => 'Templates',
    'subtitle' => 'Reusable block layouts you can apply to any page.',
    'columns' => [
        'name' => 'Name',
        'updated' => 'Updated',
        'updated_by' => 'Updated by',
    ],
    'date_format' => 'M j, Y',
    'empty' => [
        'title' => 'No templates yet',
        'description' => 'Create your first template.',
    ],
    'fields' => [
        'name' => 'Name',
        'target_page' => 'Page',
    ],
    'sections' => [
        'template' => 'Template',
    ],
    'create_title' => 'New template',
    'edit_title' => 'Edit template',
    'actions' => [
        'new' => 'New',
        'create' => 'Create',
        'cancel' => 'Cancel',
        'created' => 'Template created',
        'edit' => 'Edit',
        'save' => 'Save',
        'saved' => 'Template saved',
        'delete' => 'Delete',
        'delete_title' => 'Delete template?',
        'delete_confirm' => 'This cannot be undone.',
        'deleted' => 'Template deleted',
        'apply' => 'Apply',
        'apply_title' => 'Apply to page',
        'applied' => 'Template applied',
    ],
];
