<?php

declare(strict_types=1);

return [
    'title' => 'Form submissions',
    'subtitle' => 'Submissions from the site\'s forms.',
    'columns' => [
        'status' => 'Status',
        'delivery' => 'Delivery',
        'form' => 'Form',
        'page' => 'Page',
        'locale' => 'Locale',
        'submitted' => 'Submitted',
    ],
    'date_format' => 'n/j/Y H:i',
    'statuses' => [
        'new' => 'New',
        'read' => 'Read',
        'processed' => 'Processed',
        'discarded' => 'Discarded',
    ],
    'delivery' => [
        'pending' => 'Pending',
        'handled' => 'Sent',
        'failed' => 'Failed',
    ],
    'fields' => [
        'payload' => 'Payload',
    ],
    'values' => [
        'page_deleted' => '(page deleted)',
        'page_number' => 'Page #:id',
    ],
    'actions' => [
        'view' => 'View payload',
        'view_title' => 'Submission payload',
        'close' => 'Close',
        'retry' => 'Retry',
        'retried' => 'Submission retried successfully',
    ],
    'empty' => [
        'title' => 'No submissions yet',
        'description' => 'Form submissions will appear here.',
    ],
];
