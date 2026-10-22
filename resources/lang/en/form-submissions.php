<?php

declare(strict_types=1);

return [
    'title' => 'Form submissions',
    'subtitle' => 'Submissions from the site\'s forms.',
    'columns' => [
        'status' => 'Status',
        'lead' => 'Lead',
        'delivery' => 'Delivery',
        'form' => 'Form',
        'page' => 'Page',
        'locale' => 'Locale',
        'submitted' => 'Submitted',
    ],
    'tabs' => [
        'all' => 'All',
    ],
    'date_format' => 'M j, Y H:i',
    'statuses' => [
        'new' => 'New',
        'read' => 'Read',
        'processed' => 'Processed',
        'discarded' => 'Discarded',
    ],
    'delivery' => [
        'pending' => 'Pending',
        'processing' => 'Sending',
        'handled' => 'Sent',
        'failed' => 'Failed',
    ],
    'fields' => [
        'payload' => 'Payload',
        'error' => 'Delivery error',
    ],
    'values' => [
        'page_deleted' => '(page deleted)',
        'page_number' => 'Page #:id',
        'lead_unknown' => '—',
        'no_error' => '—',
        'empty_payload' => '(empty payload)',
    ],
    'errors' => [
        'retry_unfinished' => 'Retry did not finish.',
        'page_missing' => 'Page no longer exists.',
        'block_missing' => 'Form block no longer exists on the page.',
    ],
    'actions' => [
        'close' => 'Back to inbox',
        'retry' => 'Retry',
        'retried' => 'Submission retried successfully',
        'already_retrying' => 'This submission is already being retried or no longer needs a retry',
        'mark_processed' => 'Mark processed',
        'marked_processed' => 'Marked as processed',
        'mark_processed_bulk_title' => 'Mark selected as processed?',
        'mark_processed_bulk_confirm' => 'The selected submissions will be marked as processed.',
        'discard' => 'Discard',
        'discarded' => 'Submission discarded',
        'discard_title' => 'Discard this submission?',
        'discard_confirm' => 'It will be marked as discarded. This does not delete it.',
        'discard_bulk_title' => 'Discard selected submissions?',
        'discard_bulk_confirm' => 'The selected submissions will be marked as discarded. This does not delete them.',
        'delete' => 'Delete',
        'deleted' => 'Submission deleted',
        'delete_title' => 'Delete this submission permanently?',
        'delete_confirm' => 'This cannot be undone.',
        'delete_bulk_title' => 'Delete selected submissions permanently?',
        'delete_bulk_confirm' => 'This cannot be undone.',
    ],
    'status_section' => [
        'title' => 'Status',
        'set_to' => 'Mark as :status',
        'updated' => 'Status updated to :status',
    ],
    'comments' => [
        'title' => 'Comments',
        'label' => 'Comment',
        'placeholder' => 'Add a note for the team…',
        'submit' => 'Add comment',
        'added' => 'Comment added',
        'empty' => 'No comments yet.',
        'unknown_author' => 'User #:id',
    ],
    'empty' => [
        'title' => 'No submissions yet',
        'description' => 'Form submissions will appear here.',
    ],
];
