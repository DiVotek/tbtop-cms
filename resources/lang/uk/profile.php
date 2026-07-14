<?php

declare(strict_types=1);

return [
    'title' => 'Профіль',
    'subtitle' => 'Ваші особисті дані та мова панелі керування.',
    'sections' => [
        'personal' => 'Особиста інформація',
        'password' => 'Зміна пароля',
        'preferences' => 'Налаштування',
    ],
    'fields' => [
        'display_name' => "Ім'я для відображення",
        'email' => 'Електронна пошта',
        'locale' => 'Мова',
        'current_password' => 'Поточний пароль',
        'new_password' => 'Новий пароль',
        'confirm_password' => 'Підтвердьте новий пароль',
        'theme' => 'Тема',
    ],
    'theme' => [
        'system' => 'Системна',
        'light' => 'Світла',
        'dark' => 'Темна',
    ],
    'actions' => [
        'save' => 'Зберегти',
        'saved' => 'Збережено',
        'update_password' => 'Оновити пароль',
        'password_updated' => 'Пароль оновлено.',
    ],
];
