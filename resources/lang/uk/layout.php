<?php

declare(strict_types=1);

return [
    'title' => 'Макети',
    'subtitle' => 'Блоки шапки та підвалу, спільні для всіх сторінок.',
    'slots' => [
        'header' => 'Шапка',
        'footer' => 'Підвал',
    ],
    'slots_description' => [
        'header' => 'Блоки, що показуються над контентом на кожній сторінці.',
        'footer' => 'Блоки, що показуються під контентом на кожній сторінці.',
    ],
    'blocks' => [
        'inactive_badge' => 'Вимк.',
    ],
    'blocks_count' => ':count блок|:count блоки|:count блоків',
    'updated_at' => 'Оновлено :time',
    'empty' => 'Ще немає блоків',
    'actions' => [
        'edit' => 'Редагувати',
        'save' => 'Зберегти',
        'saved' => 'Макет збережено',
    ],
    'edit_title' => 'Редагування макета: :slot',
];
