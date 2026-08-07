<?php

declare(strict_types=1);

return [
    'common' => [
        'save' => 'Зберегти',
        'saved' => 'Збережено',
    ],
    'site' => [
        'title' => 'Налаштування сайту',
        'subtitle' => 'Загальна ідентифікація сайту: назва, логотип і фавікон.',
        'fields' => [
            'site_name' => 'Назва сайту',
            'logo' => 'Логотип',
            'favicon' => 'Фавікон',
        ],
    ],
    'seo' => [
        'title' => 'SEO-налаштування',
        'subtitle' => 'Метадані за замовчуванням для всього сайту, якщо сторінка їх не перевизначає.',
        'fields' => [
            'title_suffix' => 'Суфікс заголовка',
            'default_description' => 'Опис за замовчуванням',
            'default_og_image' => 'OG-зображення за замовчуванням',
            'indexing_enabled' => 'Дозволити пошуковим системам індексувати сайт',
        ],
        'placeholders' => [
            'title_suffix' => ' — Назва сайту',
        ],
    ],
    'notifications' => [
        'title' => 'Сповіщення',
        'subtitle' => 'Поштовий транспорт сайту та отримувачі сповіщень про нові заявки.',
        'sections' => [
            'notifications' => 'Сповіщення',
            'mail_transport' => 'Поштовий транспорт',
        ],
        'fields' => [
            'mail_enabled' => 'Пошта увімкнена',
            'recipients' => 'Отримувачі',
            'mail_host' => 'Хост SMTP',
            'mail_port' => 'Порт SMTP',
            'mail_username' => 'Користувач SMTP',
            'mail_password' => 'Пароль SMTP',
            'mail_encryption' => 'Шифрування',
            'mail_from_address' => 'Адреса відправника',
            'mail_from_name' => 'Ім’я відправника',
        ],
        'helpers' => [
            'recipients' => 'Одна email-адреса на рядок або через кому.',
            'mail_password' => 'Залиште порожнім, щоб зберегти поточне значення.',
        ],
        'encryption' => [
            'none' => 'Немає',
            'tls' => 'TLS',
            'ssl' => 'SSL',
        ],
        'actions' => [
            'send_test_email' => 'Надіслати тестовий лист',
            'test_email_sent' => 'Тестовий лист надіслано.',
            'test_email_no_recipients' => 'Додайте хоча б одного отримувача перед надсиланням тестового листа.',
            'test_email_failed' => 'Не вдалося надіслати тестовий лист: :error',
        ],
    ],
];
