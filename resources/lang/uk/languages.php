<?php

declare(strict_types=1);

return [
    'title' => 'Мови',
    'subtitle' => 'Мови, доступні в адмін-панелі та на сайті.',
    'tabs' => [
        'all' => 'Усі',
        'deleted' => 'Видалені',
    ],
    'tabs_deleted_description' => 'Мови в кошику — відновіть їх або видаліть остаточно.',
    'columns' => [
        'name' => 'Назва',
        'locale' => 'Локаль',
        'default' => 'За замовчуванням',
        'admin' => 'Адмінка',
        'frontend' => 'Сайт',
        'order' => 'Порядок',
        'deleted_at' => 'Видалено',
    ],
    'empty' => [
        'title' => 'Поки немає мов',
        'description' => 'Додайте першу мову.',
    ],
    'fields' => [
        'name' => 'Назва',
        'locale' => 'Локаль',
        'language' => 'Мова',
        'is_default' => 'Мова за замовчуванням',
        'is_admin_active' => 'Активна в адмінці',
        'is_frontend_active' => 'Активна на сайті',
        'sort_order' => 'Порядок сортування',
    ],
    'helpers' => [
        'locale' => 'Локаль не можна змінити після створення.',
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
        'delete_confirm' => 'Мову буде переміщено до розділу «Видалені», звідки її можна відновити.',
        'restore' => 'Відновити',
        'restored' => 'Мову відновлено',
        'force_delete' => 'Видалити остаточно',
        'force_delete_title' => 'Видалити остаточно?',
        'force_delete_confirm' => 'Цю дію неможливо скасувати.',
        'force_deleted' => 'Мову остаточно видалено',
    ],
    'validation' => [
        'locale_taken' => 'Мова з такою локаллю вже існує.',
        'locale_unknown' => 'Невідома локаль — оберіть мову зі списку.',
    ],
];
