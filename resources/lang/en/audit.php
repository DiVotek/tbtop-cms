<?php

declare(strict_types=1);

return [
    'title' => 'Audit log',
    'subtitle' => 'Administrative changes across CMS entities.',
    'system' => 'System',
    'yes' => 'Yes',
    'no' => 'No',
    'columns' => [
        'time' => 'Time', 'author' => 'Author', 'event' => 'Action', 'type' => 'Entity type',
        'subject' => 'Entity', 'changes' => 'Changed fields',
    ],
    'filters' => [
        'type' => 'Entity type', 'subject_id' => 'Entity ID', 'event' => 'Action',
        'author' => 'Author', 'period' => 'Period',
    ],
    'empty' => ['title' => 'No audit entries', 'description' => 'Administrative changes will appear here.'],
    'detail' => ['summary' => 'Summary', 'before' => 'Before', 'after' => 'After', 'context' => 'Context', 'none' => 'No values.'],
    'types' => [
        'page' => 'Page', 'redirect' => 'Redirect', 'language' => 'Language',
        'layout' => 'Layout', 'shared_block' => 'Shared block', 'setting' => 'Setting', 'submission' => 'Form submission',
        'not_found' => '404 entry', 'user' => 'User',
    ],
    'events' => [
        'created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted', 'restored' => 'Restored',
        'force_deleted' => 'Permanently deleted', 'published' => 'Published', 'unpublished' => 'Unpublished',
        'duplicated' => 'Duplicated', 'revision_restored' => 'Revision restored',
        'starter_blocks_applied' => 'Starter blocks applied', 'default_changed' => 'Default changed',
        'status_changed' => 'Status changed', 'delivery_retried' => 'Delivery retried',
        'comment_added' => 'Comment added', 'password_changed' => 'Password changed',
        'access_granted' => 'Access granted', 'access_revoked' => 'Access revoked',
        'profile_updated' => 'Profile updated', 'ignored' => 'Ignored', 'unignored' => 'Unignored',
    ],
];
