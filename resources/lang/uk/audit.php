<?php

declare(strict_types=1);

return [
    'title' => 'Журнал аудиту',
    'subtitle' => 'Адміністративні зміни сутностей CMS.',
    'system' => 'Система',
    'yes' => 'Так',
    'no' => 'Ні',
    'columns' => [
        'time' => 'Час', 'author' => 'Автор', 'event' => 'Дія', 'type' => 'Тип сутності',
        'subject' => 'Сутність', 'changes' => 'Змінені поля',
    ],
    'filters' => [
        'type' => 'Тип сутності', 'subject_id' => 'ID сутності', 'event' => 'Дія',
        'author' => 'Автор', 'period' => 'Період',
    ],
    'empty' => ['title' => 'Записів аудиту немає', 'description' => 'Адміністративні зміни з’являться тут.'],
    'detail' => ['summary' => 'Зведення', 'before' => 'До', 'after' => 'Після', 'context' => 'Контекст', 'none' => 'Значень немає.'],
    'types' => [
        'page' => 'Сторінка', 'redirect' => 'Перенаправлення', 'language' => 'Мова',
        'layout' => 'Макет', 'setting' => 'Налаштування', 'submission' => 'Заявка форми',
        'not_found' => 'Запис 404', 'user' => 'Користувач',
    ],
    'events' => [
        'created' => 'Створено', 'updated' => 'Оновлено', 'deleted' => 'Видалено', 'restored' => 'Відновлено',
        'force_deleted' => 'Видалено назавжди', 'published' => 'Опубліковано', 'unpublished' => 'Знято з публікації',
        'duplicated' => 'Продубльовано', 'revision_restored' => 'Ревізію відновлено',
        'starter_blocks_applied' => 'Стартові блоки застосовано', 'default_changed' => 'Типове значення змінено',
        'status_changed' => 'Статус змінено', 'delivery_retried' => 'Доставку повторено',
        'comment_added' => 'Коментар додано', 'password_changed' => 'Пароль змінено',
        'access_granted' => 'Доступ надано', 'access_revoked' => 'Доступ відкликано',
        'profile_updated' => 'Профіль оновлено', 'ignored' => 'Проігноровано', 'unignored' => 'Повернуто до обробки',
    ],
];
