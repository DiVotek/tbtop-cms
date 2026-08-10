<?php

declare(strict_types=1);

return [
    'mail' => [
        'subject' => 'Нова заявка з форми',
        'fields' => [
            'page' => 'Сторінка',
            'locale' => 'Мова',
            'submitted_at' => 'Надіслано',
        ],
        'view_submission' => 'Переглянути заявку',
    ],
    'bell' => [
        'title' => 'Нова заявка з форми',
        'view' => 'Переглянути заявку',
    ],
    'telegram' => [
        'message_title' => 'Нова заявка з форми',
        'test_message' => 'Це тестове повідомлення від Telegram-сповіщень вашої CMS.',
    ],
    'errors' => [
        'bell' => 'Не вдалося надіслати сповіщення дзвіночком: :error',
        'mail' => 'Не вдалося надіслати лист: :error',
        'telegram' => 'Не вдалося надіслати повідомлення в Telegram: :error',
    ],
];
