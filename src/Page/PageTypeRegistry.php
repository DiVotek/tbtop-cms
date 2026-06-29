<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page;

use InvalidArgumentException;
use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\Types\ArticleType;
use Tbtop\Cms\Page\Types\CategoryType;
use Tbtop\Cms\Page\Types\PageType;

class PageTypeRegistry
{
    /** @var array<string, PageTypeInterface> */
    private array $types = [];

    private bool $booted = false;

    public function get(string $type): PageTypeInterface
    {
        $this->boot();

        if (! isset($this->types[$type])) {
            throw new InvalidArgumentException("Page type [{$type}] is not registered.");
        }

        return $this->types[$type];
    }

    public function has(string $type): bool
    {
        $this->boot();

        return isset($this->types[$type]);
    }

    /** @return array<string, PageTypeInterface> */
    public function all(): array
    {
        $this->boot();

        return $this->types;
    }

    /** @return list<string> */
    public function keys(): array
    {
        $this->boot();

        return array_keys($this->types);
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ([PageType::class, CategoryType::class, ArticleType::class] as $class) {
            /** @var PageTypeInterface $type */
            $type = app($class);
            $this->types[$type->key()] = $type;
        }

        /** @var array<array-key, class-string> $custom */
        $custom = (array) config('tbtop-cms.pages.types', []);

        foreach ($custom as $class) {
            $this->register($class);
        }

        $this->booted = true;
    }

    /** @param class-string $class */
    private function register(string $class): void
    {
        if (! is_a($class, PageTypeInterface::class, true)) {
            throw new InvalidArgumentException("Page type [{$class}] must implement PageTypeInterface.");
        }

        /** @var PageTypeInterface $type */
        $type = app($class);
        $key = $type->key();

        if ($key === '') {
            throw new InvalidArgumentException("Page type [{$class}] must return a non-empty key.");
        }

        if (isset($this->types[$key])) {
            throw new InvalidArgumentException("Page type [{$key}] is already registered.");
        }

        $this->types[$key] = $type;
    }
}
