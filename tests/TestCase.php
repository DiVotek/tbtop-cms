<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests;

use Inertia\Middleware as InertiaMiddleware;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use WithWorkbench;

    protected function setUp(): void
    {
        parent::setUp();

        // The panel runs Inertia\Middleware, which answers an X-Inertia GET with
        // 409 when X-Inertia-Version disagrees with the asset version. Browsers
        // always send it; without it here a locally built
        // workbench/public/build/manifest.json would turn every page probe red.
        $this->withHeader('X-Inertia-Version', (string) (new InertiaMiddleware)->version(request()));
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
    }
}
