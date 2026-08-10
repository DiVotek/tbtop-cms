<?php

declare(strict_types=1);

return [
    'title' => 'Вхід',
    'subtitle' => 'Вхід до панелі керування',
    'fields' => [
        'email' => 'Email',
        'password' => 'Пароль',
        'remember' => "Запам'ятати мене",
    ],
    'actions' => [
        'sign_in' => 'Увійти',
    ],
    'errors' => [
        'failed' => 'Ці облікові дані не збігаються з нашими записами.',
        'throttle' => 'Забагато спроб входу. Спробуйте ще раз через :seconds секунд.',
    ],
    'links' => [
        'site' => 'Перейти на сайт',
    ],
];
