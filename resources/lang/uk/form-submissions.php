<?php

declare(strict_types=1);

return [
    'title' => 'Заявки з форм',
    'subtitle' => 'Заявки з форм сайту.',
    'columns' => [
        'status' => 'Статус',
        'lead' => 'Заявник',
        'delivery' => 'Доставка',
        'form' => 'Форма',
        'page' => 'Сторінка',
        'locale' => 'Мова',
        'submitted' => 'Надіслано',
    ],
    'tabs' => [
        'all' => 'Усі',
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
        'processing' => 'Надсилається',
        'handled' => 'Доставлено',
        'failed' => 'Помилка',
    ],
    'fields' => [
        'payload' => 'Дані заявки',
        'error' => 'Помилка доставки',
    ],
    'values' => [
        'page_deleted' => '(сторінку видалено)',
        'page_number' => 'Сторінка №:id',
        'lead_unknown' => '—',
        'no_error' => '—',
        'empty_payload' => '(порожні дані)',
    ],
    'actions' => [
        'close' => 'До списку заявок',
        'retry' => 'Повторити',
        'retried' => 'Заявку успішно повторно надіслано',
        'mark_processed' => 'Позначити обробленою',
        'marked_processed' => 'Позначено обробленою',
        'mark_processed_bulk_title' => 'Позначити обрані заявки обробленими?',
        'mark_processed_bulk_confirm' => 'Обрані заявки буде позначено як оброблені.',
        'discard' => 'Відхилити',
        'discarded' => 'Заявку відхилено',
        'discard_title' => 'Відхилити цю заявку?',
        'discard_confirm' => 'Її буде позначено як відхилену. Це не видаляє заявку.',
        'discard_bulk_title' => 'Відхилити обрані заявки?',
        'discard_bulk_confirm' => 'Обрані заявки буде позначено як відхилені. Це не видаляє їх.',
        'delete' => 'Видалити',
        'deleted' => 'Заявку видалено',
        'delete_title' => 'Видалити цю заявку остаточно?',
        'delete_confirm' => 'Цю дію неможливо скасувати.',
        'delete_bulk_title' => 'Видалити обрані заявки остаточно?',
        'delete_bulk_confirm' => 'Цю дію неможливо скасувати.',
    ],
    'status_section' => [
        'title' => 'Статус',
        'set_to' => 'Позначити як :status',
        'updated' => 'Статус змінено на :status',
    ],
    'comments' => [
        'title' => 'Коментарі',
        'label' => 'Коментар',
        'placeholder' => 'Додайте нотатку для команди…',
        'submit' => 'Додати коментар',
        'added' => 'Коментар додано',
        'empty' => 'Коментарів поки немає.',
        'unknown_author' => 'Користувач №:id',
    ],
    'empty' => [
        'title' => 'Поки немає заявок',
        'description' => 'Заявки з форм сайту з’являться тут.',
    ],
];
