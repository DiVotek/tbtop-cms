<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;
use Inertia\Middleware as InertiaMiddleware;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Collection\CollectionRegistry;
use Tbtop\Cms\Frontend\Seo\RobotsRegistry;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaTypeRegistry;
use Tbtop\Cms\Page\PageTypeRegistry;

class TestCase extends Orchestra
{
    use WithWorkbench;

    protected function setUp(): void
    {
        BlockRegistry::flushExtensions();
        CollectionRegistry::flushExtensions();
        PageTypeRegistry::flushExtensions();
        RobotsRegistry::flushExtensions();
        SchemaTypeRegistry::flushExtensions();

        parent::setUp();

        // Under LARAVEL_PARALLEL_TESTING Laravel points view.compiled at views/test_N but creates it only
        // in a setUpProcess callback the Pest runner never fires; TemplateRenderer writes there directly.
        if (ParallelTesting::token() !== false) {
            File::ensureDirectoryExists(config('view.compiled'));
        }

        // The panel runs Inertia\Middleware, which answers an X-Inertia GET with
        // 409 when X-Inertia-Version disagrees with the asset version. Browsers
        // always send it; without it here a locally built
        // workbench/public/build/manifest.json would turn every page probe red.
        $this->withHeader('X-Inertia-Version', (string) (new InertiaMiddleware)->version(request()));
    }

    /**
     * The connection the suite runs on: TBTOP_TEST_DB=mysql picks the `mysql` connection (DB_HOST, DB_PORT, … from
     * env) — `composer test:mysql` and the release CI job; the default is the SQLite :memory: `testing` connection.
     * Static so a ->skip() condition can read it when the test file loads, before the app boots.
     */
    public static function suiteConnection(): string
    {
        return (string) env('TBTOP_TEST_DB', 'testing');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', self::suiteConnection());
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // cms:install publishes tbtop-cms.php into config_path(). Left at the shared testbench skeleton, a parallel
        // worker that boots while another worker deletes it fails on `require`; a per-process directory isolates it.
        // Providers resolve their publish targets on boot, which runs after this.
        $app->useConfigPath(sys_get_temp_dir() . '/tbtop-cms-test-config-' . getmypid());
    }

    protected function defineDatabaseMigrations(): void
    {
        // On a server database RefreshDatabase's migrate:fresh already creates `users`; this helper's
        // per-test rollback would then drop it under tbtop_user_profiles' foreign key (MySQL refuses).
        if (self::suiteConnection() === 'testing') {
            $this->loadLaravelMigrations();
        }
    }
}
