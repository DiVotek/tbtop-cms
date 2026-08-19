<?php

declare(strict_types=1);

use Tbtop\Cms\Tests\Fixtures\HostAuthTestCase;
use Tbtop\Cms\Tests\TestCase;

/*
 * Two applications, so two bindings. Pest rejects overlapping in() paths, so
 * the default case is bound to everything except the directory that needs the
 * other one — listed by exclusion rather than by enumerating the rest, so a
 * new test directory keeps working without touching this file.
 */
$hostIntegration = __DIR__ . '/HostIntegration';

$default = array_values(array_filter(
    glob(__DIR__ . '/*') ?: [],
    fn (string $path): bool => $path !== $hostIntegration
        && (is_dir($path) || str_ends_with($path, 'Test.php')),
));

uses(TestCase::class)->in(...$default);

// The host owns `login` before the package boots — see the test case.
uses(HostAuthTestCase::class)->in($hostIntegration);
