<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Illuminate\Support\Facades\File;
use ReflectionClass;

/**
 * Finds the Block subclasses under a PSR-4 folder so a host does not list
 * them in config. The class name comes from the file path, so a file whose
 * class does not match its name is simply not a block.
 */
final class BlockDiscovery
{
    /**
     * The well-formed `namespace => directory` pairs of the discover config.
     * A malformed entry is skipped here and reported by problems().
     *
     * @return array<string, string>
     */
    public static function entries(mixed $discover): array
    {
        $entries = [];
        foreach (is_array($discover) ? $discover : [] as $namespace => $directory) {
            if (self::isWellFormed($namespace, $directory)) {
                /** @var string $namespace */
                $entries[$namespace] = $directory;
            }
        }

        return $entries;
    }

    /**
     * Discover entries that register nothing: `malformed` is not a
     * `'Namespace' => '/directory'` pair (a list, a non-string path),
     * `empty` is well-formed but resolves to no block class — a wrong
     * namespace or a directory that does not exist. Each is a label for
     * the console, `namespace => directory`.
     *
     * @return array{empty: list<string>, malformed: list<string>}
     */
    public static function problems(mixed $discover): array
    {
        $problems = ['empty' => [], 'malformed' => []];

        if (! is_array($discover)) {
            $problems['malformed'][] = self::describe($discover);

            return $problems;
        }

        foreach ($discover as $namespace => $directory) {
            if (! self::isWellFormed($namespace, $directory)) {
                $problems['malformed'][] = self::describe($namespace) . ' => ' . self::describe($directory);
            } elseif (self::classes((string) $namespace, $directory) === []) {
                $problems['empty'][] = "{$namespace} => {$directory}";
            }
        }

        return $problems;
    }

    /** @phpstan-assert-if-true string $directory */
    private static function isWellFormed(mixed $namespace, mixed $directory): bool
    {
        return is_string($namespace) && trim($namespace, '\\ ') !== '' && is_string($directory) && $directory !== '';
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }

    /**
     * @return list<class-string<Block>> sorted by relative path, so the registry order is stable across machines
     */
    public static function classes(string $namespace, string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = File::allFiles($directory);
        usort($files, static fn ($a, $b): int => strcmp($a->getRelativePathname(), $b->getRelativePathname()));

        $classes = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = rtrim($namespace, '\\') . '\\' . str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
            if (self::isConcreteBlock($class)) {
                /** @var class-string<Block> $class */
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private static function isConcreteBlock(string $class): bool
    {
        // class_exists() autoloads; a trait or interface at that path returns false.
        return class_exists($class)
            && is_subclass_of($class, Block::class)
            && ! (new ReflectionClass($class))->isAbstract();
    }
}
