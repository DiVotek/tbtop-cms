<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Illuminate\Foundation\Application;
use Laravel\Sanctum\SanctumServiceProvider;
use Tbtop\Cms\Tests\TestCase;

/**
 * A host that installed Sanctum, gave its user model HasApiTokens and turned
 * the pages API on before the package booted. Tests using it live in
 * tests/Api, which Pest binds to this case.
 */
class ApiTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SanctumServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('tbtop-cms.api.enabled', true);
        $app['config']->set('tbtop-cms.admin.model', ApiUser::class);
        $app['config']->set('auth.providers.users.model', ApiUser::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(dirname(__DIR__, 2) . '/vendor/laravel/sanctum/database/migrations');
    }
}
