<?php

declare(strict_types=1);

use Tbtop\Cms\Tests\Fixtures\FreshInstallTestCase;
use Tbtop\Cms\Tests\Fixtures\HostAuthTestCase;
use Tbtop\Cms\Tests\TestCase;

/*
 * Three applications, so three bindings. Pest rejects overlapping in() paths,
 * so the default case is bound to everything except the directories that need
 * another one — listed by exclusion rather than by enumerating the rest, so a
 * new test directory keeps working without touching this file.
 */
$hostIntegration = __DIR__ . '/HostIntegration';
$freshInstall = __DIR__ . '/FreshInstall';

$default = array_values(array_filter(
    glob(__DIR__ . '/*') ?: [],
    fn (string $path): bool => $path !== $hostIntegration
        && $path !== $freshInstall
        && (is_dir($path) || str_ends_with($path, 'Test.php')),
));

uses(TestCase::class)->in(...$default);

// The host owns `login` before the package boots — see the test case.
uses(HostAuthTestCase::class)->in($hostIntegration);

// No migration has ever run — see the test case.
uses(FreshInstallTestCase::class)->in($freshInstall);
