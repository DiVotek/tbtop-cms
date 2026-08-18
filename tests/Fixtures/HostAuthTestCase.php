<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Illuminate\Foundation\Application;
use Tbtop\Cms\Tests\TestCase;

/**
 * An application whose host already owns the `login` route when the package
 * boots. Tests using it live in tests/HostIntegration, which Pest binds to
 * this case instead of the default one.
 */
class HostAuthTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [HostAuthServiceProvider::class, ...parent::getPackageProviders($app)];
    }
}
