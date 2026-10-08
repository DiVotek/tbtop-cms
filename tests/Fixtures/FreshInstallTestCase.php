<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Tests\TestCase;

/**
 * A host that has just run `composer require` and nothing else: no migration
 * has ever run. TestCase::defineDatabaseMigrations() runs unconditionally for
 * every Testbench test, independent of RefreshDatabase, so the ordinary
 * TestCase always has a pre-migrated schema. Tests using this case live in
 * tests/FreshInstall, which Pest binds to it instead of the default one.
 *
 * It always runs on the SQLite :memory: `testing` connection, whatever
 * TBTOP_TEST_DB says: these tests use no DB trait, so on a server database
 * cms:install would commit its schema and seed rows for every later test.
 */
class FreshInstallTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
    }

    protected function defineDatabaseMigrations(): void {}
}
