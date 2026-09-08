<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use InvalidArgumentException;

class BlockRegistry
{
    /** @var list<callable(): list<class-string>> */
    private static array $extensions = [];

    /** @var array<string, Block> */
    private array $blocks = [];

    private bool $booted = false;

    /**
     * @param  callable(): list<class-string>  $extension  invoked once, on the registry's first use
     *
     * Must run before the singleton's first get()/has()/all()/kinds() call —
     * an extension registered after boot() has latched is stored but never read.
     */
    public static function extend(callable $extension): void
    {
        self::$extensions[] = $extension;
    }

    /** Test isolation; the cms TestCase calls it between cases. */
    public static function flushExtensions(): void
    {
        self::$extensions = [];
    }

    public function get(string $kind): Block
    {
        $this->boot();

        if (! isset($this->blocks[$kind])) {
            throw new InvalidArgumentException("Block [{$kind}] is not registered.");
        }

        return $this->blocks[$kind];
    }

    public function has(string $kind): bool
    {
        $this->boot();

        return isset($this->blocks[$kind]);
    }

    /** @return array<string, Block> */
    public function all(): array
    {
        $this->boot();

        return $this->blocks;
    }

    /** @return list<string> */
    public function kinds(): array
    {
        $this->boot();

        return array_keys($this->blocks);
    }

    /** @return array<string, Block> */
    public function forPlacement(string $placement): array
    {
        $this->boot();

        return array_filter(
            $this->blocks,
            static fn (Block $block): bool => in_array($placement, $block->placements(), true)
                || ($placement !== Placement::LAYOUT && in_array(Placement::PAGES, $block->placements(), true)),
        );
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        /** @var array<array-key, class-string> $custom */
        $custom = (array) config('tbtop-cms.blocks.types', []);

        foreach ($custom as $class) {
            $this->register($class);
        }

        foreach (self::$extensions as $extension) {
            foreach ($extension() as $class) {
                $this->register($class);
            }
        }

        $this->booted = true;
    }

    /** @param class-string $class */
    private function register(string $class): void
    {
        if (! is_a($class, Block::class, true)) {
            throw new InvalidArgumentException("Block [{$class}] must extend " . Block::class . '.');
        }

        /** @var Block $block */
        $block = app($class);
        $kind = $block->kind();

        if ($kind === '') {
            throw new InvalidArgumentException("Block [{$class}] must return a non-empty kind.");
        }

        if (isset($this->blocks[$kind])) {
            throw new InvalidArgumentException("Block [{$kind}] is already registered.");
        }

        $this->blocks[$kind] = $block;
    }
}
