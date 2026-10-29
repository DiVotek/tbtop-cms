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
            'hint' => 'Run php artisan migrate (or php artisan cms:install) to create the activity log table.',
        ],
        'routes_registered' => [
            'label' => 'Panel and frontend routes',
            'ok' => 'The admin panel and the cms frontend are both routable.',
            'missing' => 'One or more expected routes are missing.',
            'hints' => [
                'admin_missing' => 'The admin panel route is not registered. Check that tbtop-cms.admin.autoregister is true and that the CmsPanel is listed under tbtop-admin.panels.',
                'frontend_missing' => 'The cms frontend routes are not registered. Call Tbtop\\Cms\\Facades\\Cms::frontendRoutes() from a web-middleware route file, e.g. routes/web.php.',
                'both_missing' => 'Neither the admin panel nor the cms frontend routes are registered. Check tbtop-cms.admin.autoregister and call Tbtop\\Cms\\Facades\\Cms::frontendRoutes() from routes/web.php.',
            ],
        ],
        'inertia_root_view' => [
            'label' => 'Inertia root view',
            'ok' => 'The root view exists and includes the cms head partial.',
            'missing_view' => 'The configured root view ":view" does not exist.',
            'missing_include' => 'The root view at :path is missing the cms head include.',
            'include_after_head' => 'The cms head include in :path appears after the Inertia head directive.',
            'unreadable' => 'The root view at :path exists but could not be read.',
            'hints' => [
                'missing_view' => 'Create resources/views/:view.blade.php (the standard Laravel/Inertia scaffold view), or set tbtop-cms.render.root_view to an existing view.',
                'missing_include' => "Add @include('tbtop-cms::head') to your root Blade view, above the Inertia head tag — <x-inertia::head> on Inertia 3, @inertiaHead on Inertia 2.",
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
                'raw_renderer' => "Blocks are rendered with the raw '@json-render/react' Renderer at :paths — import CmsRenderer from '@cms/CmsRenderer' instead, so a block component that throws renders nothing instead of aborting the whole server render, and preview and debug name the broken block on the console.",
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
        'llms_txt' => [
            'label' => 'Static llms.txt',
            'ok' => 'No static public/llms.txt is shadowing the dynamic route.',
            'present' => 'public/llms.txt exists and shadows the dynamic /llms.txt route.',
            'hints' => [
                'present' => 'Delete public/llms.txt to let the «For AI» tab of the SEO settings build the file, or keep it if it is maintained by hand on purpose.',
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
        'pages_api' => [
            'label' => 'Pages API',
            'off' => 'The pages API is off (tbtop-cms.api.enabled).',
            'ok' => 'The pages API is on under /api/cms/v1.',
            'no_sanctum' => 'The pages API is enabled but laravel/sanctum is not installed, so its routes are not registered.',
            'no_tokens' => 'The pages API is enabled but :model cannot hold API tokens.',
            'hints' => [
                'no_sanctum' => 'Run composer require laravel/sanctum, publish and run its migration (php artisan vendor:publish --tag=sanctum-migrations && php artisan migrate), and add Laravel\\Sanctum\\HasApiTokens to the user model.',
                'no_tokens' => 'Add the Laravel\\Sanctum\\HasApiTokens trait to :model.',
            ],
        ],
        'session_driver' => [
            'label' => 'Session driver',
            'ok' => 'The session driver is ":driver".',
            'cookie' => 'The session driver is "cookie", which stores the whole session in one browser cookie.',
            'array' => 'The session driver is "array", which discards the session at the end of every request.',
            'hints' => [
                'cookie' => 'A cookie session is capped at roughly 4KB. Large page-editor forms flash more validation errors and old input than that, and the overflow is dropped silently — the form resets with no errors shown. Set SESSION_DRIVER to file, database, or redis.',
                'array' => 'An array session never survives a redirect, so logging in and every flash message silently fail. This driver is meant for tests — set SESSION_DRIVER to file, database, or redis.',
            ],
        ],
        'agent_docs_pointer' => [
            'label' => 'Agent docs pointer',
            'ok' => 'The host\'s AGENTS.md or CLAUDE.md points an agent at the host-builder guide.',
            'missing' => 'Neither AGENTS.md nor CLAUDE.md points an agent at the host-builder guide.',
            'hints' => [
                'missing' => 'Add this line to your AGENTS.md or CLAUDE.md (checked: :candidates) so an agent finds the guide: "Building on tbtop/cms → read vendor/tbtop/cms/docs/host/ai/README.md first."',
            ],
        ],
        'untyped_blocks' => [
            'label' => 'Typed block props',
            'ok' => 'Every registered block has a typed @return array{...} on resolve().',
            'warning' => 'Some registered blocks have no typed @return array{...} on resolve(): :kinds.',
            'hints' => [
                'warning' => 'Add a shaped @return array{...} docblock to each listed block\'s resolve() so `php artisan cms:blocks:types` can generate real props instead of a permissive z.record() fallback.',
            ],
        ],
        'block_discovery' => [
            'label' => 'Block discovery',
            'ok' => 'Every tbtop-cms.blocks.discover entry registers at least one block.',
            'empty' => 'These discover entries register no block: :empty.',
            'malformed' => 'These discover entries are malformed: :malformed.',
            'both' => 'These discover entries register no block: :empty. These are malformed: :malformed.',
            'hints' => [
                'warning' => 'tbtop-cms.blocks.discover is a map of PSR-4 namespace => existing directory, e.g. [\'App\\\\Cms\\\\Blocks\' => app_path(\'Cms/Blocks\')]. Check the namespace matches the classes in the folder and that the folder exists.',
            ],
        ],
        'broken_shared_references' => [
            'label' => 'Shared block references',
            'ok' => 'Every page and layout row placed by reference points at a shared block of its kind.',
            'warning' => 'Rows that point at a missing shared block, or one of another kind, per container: :containers.',
            'hints' => [
                'warning' => 'The site skips each such row. Open the page or layout slot: its editor lists the row under Problem blocks, where you can delete it.',
            ],
        ],
        'unregistered_block_rows' => [
            'label' => 'Blocks missing from the code',
            'ok' => 'Every page and layout row has a registered block kind.',
            'warning' => 'Rows whose block kind is no longer registered, per container: :containers.',
            'hints' => [
                'warning' => 'The site skips each such row and keeps its data. Register the block class again, or open the page or layout slot and delete the row under Problem blocks.',
            ],
        ],
        'page_type_pairs' => [
            'label' => 'Page types and subtypes',
            'ok' => 'Every page stores a base type in `type` and, if any, one of its registered subtypes in `subtype`.',
            'warning' => 'Pages whose type is a subtype key, or whose subtype is not a registered subtype of their type: :pairs.',
            'hints' => [
                'warning' => 'Such a page still renders, but any write that changes its type or subtype is refused until the pair is valid. Store the base in `type` and the subtype key in `subtype`, or register the missing subtype again.',
            ],
        ],
        'page_subtype_bases' => [
            'label' => 'Subtype bases',
            'ok' => 'Every page subtype\'s base() names a registered top-level type.',
            'warning' => 'These subtypes name a base that is not registered or is itself a subtype (subtype → base): :subtypes.',
            'hints' => [
                'warning' => 'No page can hold such a subtype. Make base() return the key of a registered type whose own base() is its key, or register that type.',
            ],
        ],
        'unknown_schema_types' => [
            'label' => 'Page type schema keys',
            'ok' => 'Every page type\'s schemaTypes() names registered schema types only.',
            'warning' => 'These page types list schema keys no schema type registers (type: keys): :types.',
            'hints' => [
                'warning' => 'Such a key is skipped: the page publishes the next listed key, or WebPage when none is left. Fix the key or register its class in tbtop-cms.seo.schema_types.',
            ],
        ],
    ],
];
