<?php

declare(strict_types=1);

return [
    'title' => 'Заявки з форм',
    'subtitle' => 'Заявки з форм сайту.',
    'columns' => [
        'status' => 'Статус',
        'delivery' => 'Доставка',
        'form' => 'Форма',
        'page' => 'Сторінка',
        'locale' => 'Мова',
        'submitted' => 'Надіслано',
    ],
    'date_format' => 'd.m.Y H:i',
    'statuses' => [
        'new' => 'Нова',
        'read' => 'Переглянута',
        'processed' => 'Оброблена',
        'discarded' => 'Відхилена',
    ],
    'delivery' => [
        'pending' => 'Очікує',
        'handled' => 'Доставлено',
        'failed' => 'Помилка',
    ],
    'fields' => [
        'payload' => 'Дані заявки',
    ],
    'values' => [
        'page_deleted' => '(сторінку видалено)',
        'page_number' => 'Сторінка №:id',
    ],
    'actions' => [
        'view' => 'Переглянути заявку',
        'view_title' => 'Заявка',
        'close' => 'Закрити',
        'retry' => 'Повторити',
        'retried' => 'Заявку успішно повторно надіслано',
    ],
    'empty' => [
        'title' => 'Поки немає заявок',
        'description' => 'Заявки з форм сайту з’являться тут.',
    ],
];
