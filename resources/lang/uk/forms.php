<?php

declare(strict_types=1);

return [
    // Shown to the visitor (errors.form) when a form block's handler throws.
    'submit_failed' => 'Вибачте, не вдалося обробити вашу заявку. Спробуйте ще раз.',

    // ContactForm block: the default copy when the editor leaves a field blank, and the built-in field labels.
    'contact' => [
        'heading' => 'Зв’яжіться з нами',
        'submit' => 'Надіслати',
        'success' => 'Дякуємо, ми з вами зв’яжемося.',
        'fields' => [
            'name' => 'Ім’я',
            'email' => 'Електронна пошта',
            'message' => 'Повідомлення',
        ],
    ],
];
