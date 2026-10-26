<?php

declare(strict_types=1);

return [
    'errors' => [
        'not_found' => 'Такої сторінки немає.',
        'out_of_scope' => 'Цей токен не має доступу до цієї сторінки.',
        'not_admin' => 'Користувач токена не має доступу до CMS.',
        'type_unknown' => 'Такого типу сторінки немає.',
        'already_published' => 'Сторінку вже опубліковано.',
        'delete_published' => 'Через API можна видалити лише чернетку. Спершу зніміть сторінку з публікації в адмінці.',
        'title_missing' => 'Сторінка не має заголовка мовою «:locale», яку показує сайт.',
    ],
    'validation' => [
        'html_tags' => 'У HTML можна використовувати лише :allowed; знайдено: :tags.',
        'html_attributes' => 'HTML не може мати атрибутів, крім href у посилання; знайдено: :attributes.',
        'html_links' => 'Посилання може вести лише на URL http, https, mailto чи tel або на шлях сайту; знайдено: :links.',
        'html_head' => 'HTML має містити лише вміст body: без title, meta, style та інших елементів head.',
        'unknown_key' => 'API не приймає :key.',
    ],
    'command' => [
        'no_sanctum' => 'API сторінок потребує laravel/sanctum: виконайте composer require laravel/sanctum і опублікуйте його міграцію.',
        'no_user' => 'Користувача :user не знайдено.',
        'not_admin' => ':user не має доступу до адмінки CMS. Спершу надайте його через php artisan cms:admin:grant.',
        'no_tokens' => ':model не може мати API-токенів. Додайте до нього трейт Laravel\\Sanctum\\HasApiTokens.',
        'no_parents' => 'Вкажіть категорії, до яких матиме доступ токен: --parents=<id,id>.',
        'unknown_parents' => 'Ці id не є категоріями: :ids.',
        'disabled' => 'API сторінок вимкнено. Встановіть TBTOP_CMS_API=true (tbtop-cms.api.enabled), щоб токен працював.',
        'issued' => 'Токен для :user, обмежений категоріями :parents. Його показано лише один раз:',
    ],
];
