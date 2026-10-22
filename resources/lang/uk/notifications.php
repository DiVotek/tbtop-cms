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
        'test_subject' => 'Тестовий лист від :site',
        'test_body' => 'Це тестовий лист із поштових налаштувань :site.',
    ],
    'bell' => [
        'title' => 'Нова заявка з форми',
        'view' => 'Переглянути заявку',
        'open' => 'Відкрити',
    ],
    'telegram' => [
        'message_title' => 'Нова заявка з форми',
        'test_message' => 'Це тестове повідомлення від Telegram-сповіщень вашої CMS.',
    ],
    'channels' => [
        'mail' => 'Email',
        'telegram' => 'Telegram',
        'bell' => 'Дзвіночок',
    ],
    'errors' => [
        'bell' => 'Не вдалося надіслати сповіщення дзвіночком: :error',
        'mail' => 'Не вдалося надіслати лист: :error',
        'channel' => 'Не вдалося надіслати сповіщення :channel: :error',
        'render' => 'Не вдалося сформувати сповіщення :channel: :error',
        'mail_no_recipients' => 'Поштові сповіщення увімкнено, але отримувачів не налаштовано.',
        'telegram' => 'Не вдалося надіслати повідомлення в Telegram: :error',
        'telegram_not_configured' => 'Telegram-сповіщення увімкнено, але токен бота або ID чатів не налаштовано.',
    ],
];
