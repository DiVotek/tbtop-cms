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

/*
 * Structure-tree walkers shared by more than one admin test file. They live
 * here rather than in a test file so selecting a single file still defines
 * them — PSR-4 does not autoload plain functions.
 */

/** @param array<string, mixed> $node @return array<string, mixed>|null */
function findNodeByKind(array $node, string $kind): ?array
{
    if (($node['kind'] ?? null) === $kind) {
        return $node;
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (! is_array($child)) {
            continue;
        }
        $found = findNodeByKind($child, $kind);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * @param  array<string, mixed>  $node
 * @param  list<array<string, mixed>>  $out
 */
function collectNodesByKind(array $node, string $kind, array &$out): void
{
    if (($node['kind'] ?? null) === $kind) {
        $out[] = $node;
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            collectNodesByKind($child, $kind, $out);
        }
    }
    foreach ((array) ($node['options']['tabs'] ?? []) as $tab) {
        if (is_array($tab) && isset($tab['body']) && is_array($tab['body'])) {
            collectNodesByKind($tab['body'], $kind, $out);
        }
    }
    // A liveRegion node (SerpPreview/OgPreview) carries its render output
    // under options.initial rather than options.children.
    foreach ((array) ($node['options']['initial'] ?? []) as $initial) {
        if (is_array($initial)) {
            collectNodesByKind($initial, $kind, $out);
        }
    }
}

/**
 * Recursively collects every field `name` under a node, regardless of
 * nesting depth — the active/sort pair lives inside a nested flex row, so a
 * flat array_column() over the top-level children misses them.
 *
 * @param  array<string, mixed>  $node
 * @param  list<string>  $names
 */
function collectFieldNames(array $node, array &$names): void
{
    if (isset($node['name']) && is_string($node['name'])) {
        $names[] = $node['name'];
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            collectFieldNames($child, $names);
        }
    }
}
