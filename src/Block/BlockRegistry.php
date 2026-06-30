<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use InvalidArgumentException;

class BlockRegistry
{
    /** @var array<string, Block> */
    private array $blocks = [];

    private bool $booted = false;

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
