<?php

declare(strict_types=1);

return [
    'title' => 'Мови',
    'subtitle' => 'Мови, доступні в адмін-панелі та на сайті.',
    'columns' => [
        'name' => 'Назва',
        'locale' => 'Локаль',
        'default' => 'За замовчуванням',
        'admin' => 'Адмінка',
        'frontend' => 'Сайт',
        'order' => 'Порядок',
    ],
    'empty' => [
        'title' => 'Поки немає мов',
        'description' => 'Додайте першу мову.',
    ],
    'fields' => [
        'name' => 'Назва',
        'locale' => 'Локаль',
        'is_default' => 'Мова за замовчуванням',
        'is_admin_active' => 'Активна в адмінці',
        'is_frontend_active' => 'Активна на сайті',
        'sort_order' => 'Порядок сортування',
    ],
    'helpers' => [
        'locale' => 'Локаль не можна змінити після створення.',
    ],
    'placeholders' => [
        'locale' => 'en',
    ],
    'create_title' => 'Нова мова',
    'edit_title' => 'Редагувати мову',
    'actions' => [
        'new' => 'Нова',
        'create' => 'Створити',
        'cancel' => 'Скасувати',
        'created' => 'Мову створено',
        'saved' => 'Мову збережено',
        'edit' => 'Редагувати',
        'delete' => 'Видалити',
        'deleted' => 'Мову видалено',
        'delete_title' => 'Видалити мову?',
        'delete_confirm' => 'Цю дію не можна скасувати.',
    ],
    'validation' => [
        'locale_required' => 'Локаль обов\'язкова.',
        'locale_taken' => 'Мова з такою локаллю вже існує.',
    ],
];
