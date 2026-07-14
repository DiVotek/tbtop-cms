<?php

declare(strict_types=1);

return [
    'title' => 'Меню',
    'subtitle' => 'Іменовані навігаційні меню. Тут будується дерево пунктів; підключення до сайту — окремо.',
    'create_title' => 'Нове меню',
    'edit_title' => 'Редагувати меню',
    'date_format' => 'd.m.Y',
    'columns' => [
        'name' => 'Назва',
        'key' => 'Ключ',
        'updated' => 'Оновлено',
    ],
    'sections' => [
        'menu' => 'Меню',
        'items' => 'Пункти',
    ],
    'fields' => [
        'name' => 'Назва',
        'key' => 'Ключ',
        'key_hint' => 'Стабільний ідентифікатор для посилання на це меню, напр. main або footer.',
        'items' => 'Пункти',
        'children' => 'Підпункти',
        'label' => 'Підпис',
        'type' => 'Тип',
        'page' => 'Сторінка',
        'url' => 'URL',
        'anchor' => 'Якір',
        'anchor_hint' => 'Якір на сторінці, напр. #contact.',
    ],
    'types' => [
        'page' => 'Сторінка',
        'url' => 'URL',
        'anchor' => 'Якір',
    ],
    'actions' => [
        'new' => 'Нове',
        'edit' => 'Редагувати',
        'save' => 'Зберегти',
        'saved' => 'Меню збережено',
        'delete' => 'Видалити',
        'deleted' => 'Меню видалено',
        'delete_title' => 'Видалити меню?',
        'delete_confirm' => 'Цю дію не можна скасувати.',
    ],
    'empty' => [
        'title' => 'Меню ще немає',
        'description' => 'Створіть перше меню.',
    ],
];
