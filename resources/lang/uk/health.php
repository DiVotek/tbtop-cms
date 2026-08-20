<?php

declare(strict_types=1);

return [
    'title' => 'Стан сайту',
    'subtitle' => 'Діагностика типових помилок встановлення та налаштування.',
    'columns' => [
        'status' => 'Статус',
        'check' => 'Перевірка',
        'message' => 'Повідомлення',
        'hint' => 'Підказка',
    ],
    'statuses' => [
        'ok' => 'Гаразд',
        'warning' => 'Попередження',
        'error' => 'Помилка',
    ],
    'runner' => [
        'threw' => 'Перевірка неочікувано впала: :error',
        'threw_hint' => 'Це помилка в самій перевірці, а не у ваших налаштуваннях. Будь ласка, повідомте про неї.',
    ],
    'console' => [
        'ok' => 'Гаразд',
        'warning' => 'Попередження',
        'error' => 'Помилка',
        'summary' => ':ok гаразд, попереджень: :warnings, помилок: :errors.',
    ],
    'page' => [
        'sqlite_missing' => 'Для табличного вигляду потрібне PHP-розширення sqlite (pdo_sqlite), якого немає на цьому сервері — показано простий звіт. Встановіть pdo_sqlite для повної таблиці або скористайтеся `php artisan cms:doctor`.',
    ],
    'checks' => [
        'activity_log_table' => [
            'label' => 'Таблиця журналу аудиту',
            'ok' => 'Таблиця ":table" має потрібну схему журналу аудиту.',
            'missing' => 'Таблиця ":table" або потрібні стовпці відсутні.',
            'hint' => 'Виконайте php artisan cms:install, щоб опублікувати й запустити міграції Spatie activity log.',
        ],
        'routes_registered' => [
            'label' => 'Маршрути панелі та фронтенду',
            'ok' => 'Адмінпанель і фронтенд cms доступні за маршрутами.',
            'missing' => 'Одного чи кількох очікуваних маршрутів немає.',
            'hints' => [
                'admin_missing' => 'Маршрут адмінпанелі не зареєстровано. Перевірте, що tbtop-cms.admin.autoregister має значення true і що CmsPanel вказано в tbtop-admin.panels.',
                'frontend_missing' => 'Маршрути фронтенду cms не зареєстровано. Викличте Tbtop\\Cms\\Facades\\Cms::frontendRoutes() у файлі маршрутів з web middleware, наприклад routes/web.php.',
                'both_missing' => 'Не зареєстровано ні маршрут адмінпанелі, ні маршрути фронтенду cms. Перевірте tbtop-cms.admin.autoregister і викличте Tbtop\\Cms\\Facades\\Cms::frontendRoutes() у routes/web.php.',
            ],
        ],
        'inertia_root_view' => [
            'label' => 'Кореневий вигляд Inertia',
            'ok' => 'Кореневий вигляд існує і містить partial з head для cms.',
            'missing_view' => 'Налаштований кореневий вигляд ":view" не існує.',
            'missing_include' => 'У кореневому вигляді :path немає include для head cms.',
            'unreadable' => 'Кореневий вигляд за шляхом :path існує, але його не вдалося прочитати.',
            'hints' => [
                'missing_view' => 'Створіть resources/views/:view.blade.php (стандартний scaffold-вигляд Laravel/Inertia) або вкажіть у tbtop-cms.render.root_view існуючий вигляд.',
                'missing_include' => "Додайте @include('tbtop-cms::head') у кореневий Blade-вигляд, над тегом head від Inertia — <x-inertia::head> в Inertia 3, @inertiaHead в Inertia 2.",
                'unreadable' => 'Перевірте, що :path — звичайний файл, який користувач вебсервера може прочитати; до цього кожен рендер сторінки завершується помилкою.',
            ],
        ],
        'js_wiring' => [
            'label' => 'Підключення JS-точки входу та стилів',
            'ok' => 'Компонент admin-сторінки, імпорт блоків і рядок @source Tailwind знайдено.',
            'warning' => 'Деякі клієнтські підключення не вдалося підтвердити за файлами.',
            'hints' => [
                'combined' => ':details',
                'entry_not_found' => 'JS-точку входу не знайдено (шукали: :candidates).',
                'unreadable' => 'Файл :path існує, але його не вдалося прочитати (перевірте права доступу).',
                'blocks_import_missing' => "У JS-точці входу :path немає імпорту '@cms/admin/blocks' — власні admin-блоки рендеритимуться як заглушки невідомого типу.",
                'admin_page_not_found' => 'Компонент admin-сторінки не знайдено (шукали: :candidates).',
                'admin_page_import_missing' => "Компонент admin-сторінки :path не імпортує з '@tbtop/inertia-admin' — Inertia не зможе розпізнати компонент admin/page.",
                'css_not_found' => 'CSS-точку входу не знайдено (шукали: :candidates).',
                'source_missing' => 'У стилях :path немає рядка @source для vendor/tbtop/cms — класи admin DSL можуть не потрапити до збірки й рендеритися без стилів.',
            ],
        ],
        'package_versions_match' => [
            'label' => 'Версії PHP/JS пакета tbtop/admin',
            'ok' => 'PHP і JS пакети повідомляють однакову версію :version.',
            'mismatch' => 'PHP повідомляє :php, а JS — :js.',
            'unknown' => 'PHP повідомляє :php, а JS — :js.',
            'unknown_value' => 'невідомо',
            'hints' => [
                'mismatch' => 'Оновіть composer.json (tbtop/admin) і package.json (@tbtop/inertia-admin) до одного релізу, потім перевстановіть залежності.',
                'unknown' => 'Виконайте composer show tbtop/admin і перевірте node_modules/@tbtop/inertia-admin/package.json, щоб переконатися, що обидва пакети встановлено.',
            ],
        ],
        'robots_txt' => [
            'label' => 'Статичний robots.txt',
            'ok' => 'Статичний public/robots.txt відсутній, динамічний маршрут не перекрито.',
            'present' => 'Файл public/robots.txt існує і перекриває динамічний маршрут /robots.txt.',
            'hints' => [
                'present' => 'Видаліть public/robots.txt — Laravel віддає статичні файли з public/ до будь-якого маршруту, тому цей файл непомітно вимикає перемикач індексації та посилання на карту сайту з SEO-налаштувань.',
            ],
        ],
        'media_disk_writable' => [
            'label' => 'Диск медіа доступний для запису',
            'ok' => 'Диск ":disk" прийняв пробний файл (запис і видалення).',
            'failed' => 'Запис на диск ":disk" не вдався: :error',
            'delete_failed' => 'Диск ":disk" прийняв пробний запис, але видалити пробний файл не вдалося (залишився у :path).',
            'write_verified_false' => 'файл записано, але після цього його не знайдено',
            'hints' => [
                'delete_failed' => 'Обліковий доступ до диска ":disk" дозволяє запис, але не видалення — заміна та видалення медіа залишатимуть файли. Надайте право на видалення і приберіть залишені пробні файли в tbtop-cms-health-check/.',
                'failed' => 'Перевірте налаштування диска ":disk" у config/filesystems.php та параметр tbtop-admin.media.disk — медіатека та завантаження файлів не працюватимуть, поки цей диск недоступний для запису.',
            ],
        ],
        'notifications_configured' => [
            'label' => 'Налаштування сповіщень',
            'ok' => 'Увімкнені канали сповіщень мають усі потрібні налаштування.',
            'warning' => 'Увімкненому каналу сповіщень бракує обов’язкових налаштувань.',
            'hints' => [
                'combined' => ':details',
                'mail_no_recipients' => 'Email-сповіщення увімкнено, але отримувачів не вказано — додайте хоча б одного на сторінці налаштувань сповіщень.',
                'mail_no_from' => 'Email-сповіщення увімкнено, але адресу відправника не налаштовано (ні на сторінці налаштувань сповіщень, ні в config/mail.php).',
                'telegram_no_token' => 'Telegram-сповіщення увімкнено, але токен бота не вказано — додайте його на сторінці налаштувань сповіщень.',
                'telegram_no_chat_ids' => 'Telegram-сповіщення увімкнено, але ідентифікатори чатів не вказано — додайте хоча б один на сторінці налаштувань сповіщень.',
            ],
        ],
        'scheduler_heartbeat' => [
            'label' => 'Пульс планувальника',
            'ok' => 'Планувальник спрацьовував протягом останніх 5 хвилин.',
            'missing' => 'Пульс планувальника ще жодного разу не зафіксовано.',
            'stale' => 'Останній пульс планувальника був :minutes хв тому.',
            'hints' => [
                'missing' => 'Це нормально одразу після встановлення — пульс з’являється лише після першого спрацювання планувальника. Якщо він відсутній ще через кілька хвилин, переконайтеся, що cron сервера щохвилини викликає `php artisan schedule:run` (або `php artisan schedule:work` під час розробки).',
                'stale' => 'Схоже, планувальник не працює. Переконайтеся, що cron сервера щохвилини викликає `php artisan schedule:run` (або `php artisan schedule:work` під час розробки).',
            ],
        ],
        'app_url' => [
            'label' => 'Коректність APP_URL',
            'ok' => 'APP_URL встановлено як :url.',
            'not_production' => 'Пропущено поза production-середовищем (зараз ":environment").',
            'suspicious' => 'APP_URL встановлено як ":url" — схоже на локальну адресу для розробки.',
            'malformed' => 'APP_URL має значення ":url" — це не абсолютний http(s) URL з хостом.',
            'hints' => [
                'malformed' => 'Вкажіть у .env повний production-URL (https://example.com) — з APP_URL будується кожен абсолютний URL сайту (canonical, sitemap, Open Graph), і зараз вони некоректні.',
                'suspicious' => 'Встановіть у .env реальний production URL в APP_URL — він використовується в canonical-тегах, карті сайту та посиланнях Open Graph.',
            ],
        ],
        'owner_and_default_language' => [
            'label' => 'Власник і мова за замовчуванням',
            'ok' => 'Існує обліковий запис власника та активна мова фронтенду за замовчуванням.',
            'missing' => 'Відсутній обов’язковий інваріант адмінки.',
            'hints' => [
                'owner_missing' => 'Жоден користувач не має is_owner у своєму записі cms_user_profiles. Виконайте php artisan cms:admin:create, щоб створити такого, або призначте власника наявному адміну в базі даних.',
                'language_missing' => 'Немає активної мови фронтенду за замовчуванням. Створіть або активуйте її на сторінці "Мови" в адмінці.',
                'both_missing' => 'Немає ні власника, ні активної мови фронтенду за замовчуванням. Виконайте php artisan cms:install та php artisan cms:admin:create, щоб налаштувати обидва.',
            ],
        ],
        'session_driver' => [
            'label' => 'Драйвер сесій',
            'ok' => 'Драйвер сесій — ":driver".',
            'cookie' => 'Драйвер сесій — "cookie", який зберігає всю сесію в одній cookie браузера.',
            'array' => 'Драйвер сесій — "array", який відкидає сесію наприкінці кожного запиту.',
            'hints' => [
                'cookie' => 'Сесія в cookie обмежена приблизно 4КБ. Великі форми редактора сторінок передають більше помилок валідації та old input, ніж уміщається, і надлишок мовчки відкидається — форма скидається без жодних помилок. Встановіть SESSION_DRIVER у file, database або redis.',
                'array' => 'Сесія array не переживає редирект, тому вхід у панель і всі flash-повідомлення мовчки не спрацьовують. Цей драйвер призначений для тестів — встановіть SESSION_DRIVER у file, database або redis.',
            ],
        ],
    ],
];
