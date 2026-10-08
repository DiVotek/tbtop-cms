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
 * The first field with this `name` at any depth. Fields sit inside layout
 * wrappers (the SEO tab lays them out in a grid beside the checklist), so a
 * firstWhere() over the top-level children finds nothing.
 *
 * @param  array<string, mixed>  $node
 * @return array<string, mixed>|null
 */
function findFieldByName(array $node, string $name): ?array
{
    if (($node['name'] ?? null) === $name) {
        return $node;
    }

    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child) && ($found = findFieldByName($child, $name)) !== null) {
            return $found;
        }
    }

    // A repeater's row fields (e.g. BlockContent::make()'s `content` field)
    // sit under options.fields, not options.children.
    foreach ((array) ($node['options']['fields'] ?? []) as $child) {
        if (is_array($child) && ($found = findFieldByName($child, $name)) !== null) {
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

/**
 * Evaluates a serialized `hiddenIf` condition against form data the way the
 * admin client does (conditionCompiler.ts): eq compares scalars as strings,
 * empty means null, '' or an empty list.
 *
 * @param  array<string, mixed>  $cond
 * @param  array<string, mixed>  $data
 */
function evalCond(array $cond, array $data): bool
{
    $value = isset($cond['field']) ? ($data[$cond['field']] ?? null) : null;
    $equals = static fn (mixed $a, mixed $b): bool => $a === $b || (is_scalar($a) && is_scalar($b) && (string) $a === (string) $b);
    $isEmpty = $value === null || $value === '' || $value === [];

    return match ($cond['op']) {
        'eq' => $equals($value, $cond['value']),
        'neq' => ! $equals($value, $cond['value']),
        'in' => array_any($cond['value'], static fn (mixed $item): bool => $equals($value, $item)),
        'notIn' => ! array_any($cond['value'], static fn (mixed $item): bool => $equals($value, $item)),
        'empty' => $isEmpty,
        'notEmpty' => ! $isEmpty,
        'all' => array_all($cond['conds'], static fn (array $c): bool => evalCond($c, $data)),
        'any' => array_any($cond['conds'], static fn (array $c): bool => evalCond($c, $data)),
        'not' => ! evalCond($cond['cond'], $data),
    };
}

/**
 * Whether the client renders $node for the given form data: neither a static
 * `hidden` meta nor a `hiddenIf` that holds.
 *
 * @param  array<string, mixed>  $node
 * @param  array<string, mixed>  $data
 */
function isShownFor(array $node, array $data): bool
{
    $meta = $node['meta'] ?? [];

    return ($meta['hidden'] ?? false) !== true
        && ! (isset($meta['hiddenIf']) && evalCond($meta['hiddenIf'], $data));
}

/*
 * toBe() for a value read back from a JSON column: MySQL stores JSON objects
 * with normalised key order, SQLite keeps insertion order. Object keys are
 * compared in any order; list order and every scalar stay strict.
 */
expect()->extend('toBeIgnoringKeyOrder', function (mixed $expected) {
    return expect(canonicalKeyOrder($this->value))->toBe(canonicalKeyOrder($expected));
});

function canonicalKeyOrder(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $value = array_map(canonicalKeyOrder(...), $value);

    if (! array_is_list($value)) {
        ksort($value);
    }

    return $value;
}

/**
 * Whether the suite runs on MySQL (see TestCase::suiteConnection()). For
 * ->skip() on a test that reproduces a known MySQL-only bug; the reason names
 * the card that tracks it.
 */
function runsOnMySql(): bool
{
    return TestCase::suiteConnection() === 'mysql';
}

/** Deletes the tbtop-cms.php that cms:install publishes, so the next test in the process starts without it. */
function forgetPublishedConfig(): void
{
    $published = config_path('tbtop-cms.php');

    if (is_file($published)) {
        unlink($published);
    }
}
