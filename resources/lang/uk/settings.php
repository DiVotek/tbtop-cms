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
        'subtitle' => 'Вихідні поштові сповіщення та дані SMTP.',
        'fields' => [
            'mail_enabled' => 'Пошта увімкнена',
            'recipients' => 'Отримувачі',
            'smtp_password' => 'Пароль SMTP',
        ],
        'helpers' => [
            'recipients' => 'Одна email-адреса на рядок.',
            'smtp_password' => 'Залиште порожнім, щоб зберегти поточне значення.',
        ],
    ],
];
