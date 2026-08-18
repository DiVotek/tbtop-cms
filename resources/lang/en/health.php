<?php

declare(strict_types=1);

return [
    'title' => 'Site health',
    'subtitle' => 'Diagnostics for common install and configuration mistakes.',
    'columns' => [
        'status' => 'Status',
        'check' => 'Check',
        'message' => 'Message',
        'hint' => 'Hint',
    ],
    'statuses' => [
        'ok' => 'OK',
        'warning' => 'Warning',
        'error' => 'Error',
    ],
    'runner' => [
        'threw' => 'The check crashed unexpectedly: :error',
        'threw_hint' => 'This is a bug in the check itself, not your configuration. Please report it.',
    ],
    'console' => [
        'ok' => 'OK',
        'warning' => 'WARN',
        'error' => 'FAIL',
        'summary' => ':ok ok, :warnings warning(s), :errors error(s).',
    ],
    'page' => [
        'sqlite_missing' => 'The table view needs the sqlite PHP extension (pdo_sqlite), which this server does not have — showing the plain report instead. Install pdo_sqlite to get the full table, or use `php artisan cms:doctor`.',
    ],
    'checks' => [
        'activity_log_table' => [
            'label' => 'Activity log table',
            'ok' => 'The ":table" table has the required activity log schema.',
            'missing' => 'The ":table" table or required columns are missing.',
            'hint' => 'Run php artisan cms:install to publish and run the Spatie activity log migrations.',
        ],
        'routes_registered' => [
            'label' => 'Panel and frontend routes',
            'ok' => 'The admin panel and the cms frontend are both routable.',
            'missing' => 'One or more expected routes are missing.',
            'hints' => [
                'admin_missing' => 'The admin panel route is not registered. Check that tbtop-cms.admin.autoregister is true and that the CmsPanel is listed under tbtop-admin.panels.',
                'frontend_missing' => 'The cms frontend routes are not registered. Call Tbtop\\Cms\\Cms::frontendRoutes() from a web-middleware route file, e.g. routes/web.php.',
                'both_missing' => 'Neither the admin panel nor the cms frontend routes are registered. Check tbtop-cms.admin.autoregister and call Tbtop\\Cms\\Cms::frontendRoutes() from routes/web.php.',
            ],
        ],
        'inertia_root_view' => [
            'label' => 'Inertia root view',
            'ok' => 'The root view exists and includes the cms head partial.',
            'missing_view' => 'The configured root view ":view" does not exist.',
            'missing_include' => 'The root view at :path is missing the cms head include.',
            'unreadable' => 'The root view at :path exists but could not be read.',
            'hints' => [
                'missing_view' => 'Create resources/views/:view.blade.php (the standard Laravel/Inertia scaffold view), or set tbtop-cms.render.root_view to an existing view.',
                'missing_include' => "Add @include('tbtop-cms::head') above @inertiaHead in your root Blade view.",
                'unreadable' => 'Check that :path is a regular file the web server user can read — every page render fails until it is.',
            ],
        ],
        'js_wiring' => [
            'label' => 'JS entry and stylesheet wiring',
            'ok' => 'The admin page component, blocks import, and Tailwind source line were all found.',
            'warning' => 'Some client-side wiring could not be confirmed from the filesystem.',
            'hints' => [
                'combined' => ':details',
                'entry_not_found' => 'No JS entry file found (looked for: :candidates).',
                'unreadable' => 'The file at :path exists but could not be read (check its permissions).',
                'blocks_import_missing' => "The JS entry at :path does not import '@cms/admin/blocks' — custom admin blocks will render as unknown-kind placeholders.",
                'admin_page_not_found' => 'No admin page component found (looked for: :candidates).',
                'admin_page_import_missing' => "The admin page component at :path does not import from '@tbtop/inertia-admin' — Inertia will fail to resolve the admin/page component.",
                'css_not_found' => 'No CSS entry file found (looked for: :candidates).',
                'source_missing' => 'The stylesheet at :path has no @source line for vendor/tbtop/cms — admin DSL classes may compile to nothing and render unstyled.',
            ],
        ],
        'package_versions_match' => [
            'label' => 'tbtop/admin PHP/JS versions',
            'ok' => 'PHP and JS packages both report version :version.',
            'mismatch' => 'PHP reports :php but JS reports :js.',
            'unknown' => 'PHP reports :php and JS reports :js.',
            'unknown_value' => 'unknown',
            'hints' => [
                'mismatch' => 'Update composer.json (tbtop/admin) and package.json (@tbtop/inertia-admin) to the same release, then reinstall.',
                'unknown' => 'Run composer show tbtop/admin and check node_modules/@tbtop/inertia-admin/package.json to confirm both are installed.',
            ],
        ],
        'robots_txt' => [
            'label' => 'Static robots.txt',
            'ok' => 'No static public/robots.txt is shadowing the dynamic route.',
            'present' => 'public/robots.txt exists and shadows the dynamic /robots.txt route.',
            'hints' => [
                'present' => 'Delete public/robots.txt — Laravel serves static files under public/ before any route runs, so it silently disables the SEO settings indexing toggle and sitemap reference.',
            ],
        ],
        'media_disk_writable' => [
            'label' => 'Media disk writable',
            'ok' => 'The ":disk" disk accepted a write+delete probe file.',
            'failed' => 'Writing to the ":disk" disk failed: :error',
            'delete_failed' => 'The ":disk" disk accepted the probe write, but the probe file could not be deleted (left at :path).',
            'write_verified_false' => 'the file was written but could not be found afterwards',
            'hints' => [
                'delete_failed' => 'The credential for the ":disk" disk can write but not delete — media replace and delete will leave files behind. Grant delete permission and remove the leftover probe files under tbtop-cms-health-check/.',
                'failed' => 'Check the ":disk" disk configuration in config/filesystems.php and the tbtop-admin.media.disk setting — the media library and uploads will fail until this disk is writable.',
            ],
        ],
        'notifications_configured' => [
            'label' => 'Notifications configuration',
            'ok' => 'Enabled notification channels have the settings they need.',
            'warning' => 'An enabled notification channel is missing required settings.',
            'hints' => [
                'combined' => ':details',
                'mail_no_recipients' => 'Mail notifications are enabled but no recipients are set — add at least one on the Notifications settings page.',
                'mail_no_from' => 'Mail notifications are enabled but no from-address is configured (neither the Notifications settings page nor config/mail.php).',
                'telegram_no_token' => 'Telegram notifications are enabled but no bot token is set — add one on the Notifications settings page.',
                'telegram_no_chat_ids' => 'Telegram notifications are enabled but no chat ids are set — add at least one on the Notifications settings page.',
            ],
        ],
        'scheduler_heartbeat' => [
            'label' => 'Scheduler heartbeat',
            'ok' => 'The scheduler ticked within the last 5 minutes.',
            'missing' => 'No scheduler heartbeat has been recorded yet.',
            'stale' => 'The last scheduler heartbeat was :minutes minute(s) ago.',
            'hints' => [
                'missing' => "This is expected right after install — the heartbeat only appears once the scheduler has run at least once. If it's still missing after a few minutes, make sure your server's cron runs `php artisan schedule:run` every minute (or `php artisan schedule:work` in development).",
                'stale' => "The scheduler appears not to be running. Make sure your server's cron runs `php artisan schedule:run` every minute (or `php artisan schedule:work` in development).",
            ],
        ],
        'app_url' => [
            'label' => 'APP_URL sanity',
            'ok' => 'APP_URL is set to :url.',
            'not_production' => 'Skipped outside the production environment (currently ":environment").',
            'suspicious' => 'APP_URL is set to ":url", which looks like a local development address.',
            'malformed' => 'APP_URL is set to ":url", which is not an absolute http(s) URL with a host.',
            'hints' => [
                'malformed' => 'Set APP_URL in .env to the full production URL (https://example.com) — every absolute URL the site generates (canonical tags, sitemap, Open Graph) is built from it and is currently invalid.',
                'suspicious' => 'Set APP_URL in .env to the real production URL — it feeds canonical tags, sitemap entries, and Open Graph URLs.',
            ],
        ],
        'owner_and_default_language' => [
            'label' => 'Owner and default language',
            'ok' => 'An owner account and an active default frontend language both exist.',
            'missing' => 'A required admin invariant is missing.',
            'hints' => [
                'owner_missing' => 'No user has is_owner set on their cms_user_profiles row. Run php artisan cms:admin:create to create one, or promote an existing admin in the database.',
                'language_missing' => 'No frontend-active default language exists. Create or activate one from the Languages admin page.',
                'both_missing' => 'No owner account and no active default frontend language exist. Run php artisan cms:install and php artisan cms:admin:create to set up both.',
            ],
        ],
    ],
];
