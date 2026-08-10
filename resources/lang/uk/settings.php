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
            'telegram' => 'Telegram',
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
            'telegram_enabled' => 'Telegram увімкнено',
            'telegram_bot_token' => 'Токен бота',
            'telegram_chat_ids' => 'ID чатів',
        ],
        'helpers' => [
            'recipients' => 'Одна email-адреса на рядок або через кому.',
            'mail_password' => 'Залиште порожнім, щоб зберегти поточне значення.',
            'telegram_chat_ids' => 'Один ID чату на рядок або через кому. Скористайтесь кнопкою «Створити запрошення» нижче, щоб додати чат, не знаючи його ID.',
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
            'send_test_telegram' => 'Надіслати тестове повідомлення Telegram',
            'test_telegram_sent' => 'Тестове повідомлення Telegram надіслано.',
            'test_telegram_failed' => 'Не вдалося надіслати тестове повідомлення Telegram: :error',
            'telegram_no_token' => 'Збережіть токен бота перед використанням сповіщень Telegram.',
            'telegram_no_chat_ids' => 'Додайте хоча б один ID чату перед надсиланням тестового повідомлення.',
            'create_telegram_invite' => 'Створити запрошення',
            'telegram_invite_created' => 'Запрошення створено. Відкрийте це посилання в Telegram і натисніть Start: :link',
            'telegram_invite_failed' => 'Не вдалося створити запрошення: :error',
            'check_telegram_invite' => 'Перевірити запрошення',
            'telegram_invite_expired' => 'Немає активного запрошення, або його термін дії минув. Створіть нове.',
            'telegram_invite_pending' => 'Повідомлення від цього запрошення ще не надійшло. Відкрийте посилання в Telegram, натисніть Start, потім перевірте ще раз.',
            'telegram_invite_check_failed' => 'Не вдалося перевірити запрошення: :error',
            'telegram_invite_claimed' => 'Чат «:chat» (:id) додано до списку отримувачів.',
        ],
    ],
];
