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
 */
class FreshInstallTestCase extends TestCase
{
    protected function defineDatabaseMigrations(): void {}
}
