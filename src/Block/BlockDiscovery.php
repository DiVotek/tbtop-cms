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
