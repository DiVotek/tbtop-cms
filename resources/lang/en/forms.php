<?php

declare(strict_types=1);

return [
    // Shown to the visitor (errors.form) when a form block's handler throws.
    'submit_failed' => 'Sorry, we could not process your submission. Please try again.',

    // ContactForm block: the default copy when the editor leaves a field blank, and the built-in field labels.
    'contact' => [
        'heading' => 'Contact us',
        'submit' => 'Send',
        'success' => 'Thanks, we will be in touch.',
        'fields' => [
            'name' => 'Name',
            'email' => 'Email',
            'message' => 'Message',
        ],
    ],
];
