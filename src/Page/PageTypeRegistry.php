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
    /** @var list<callable(): list<class-string>> */
    private static array $extensions = [];

    /** @var array<string, PageTypeInterface> */
    private array $types = [];

    private bool $booted = false;

    /**
     * @param  callable(): list<class-string>  $extension  invoked once, on the registry's first use
     *
     * Must run before the singleton's first get()/has()/all()/keys() call —
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

    /**
     * False for an unregistered type: pages.type is unconstrained, so a page
     * outlives its type's registration and must stay editable and renderable.
     */
    public function isSourceBacked(string $type): bool
    {
        return $this->has($type) && $this->get($type)->isSourceBacked();
    }

    /** @return list<string> */
    public function sourceBackedKeys(): array
    {
        $this->boot();

        return array_values(array_map(
            static fn (PageTypeInterface $type): string => $type->key(),
            array_filter($this->types, static fn (PageTypeInterface $type): bool => $type->isSourceBacked()),
        ));
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ([PageType::class, CategoryType::class, ArticleType::class] as $class) {
            // Container-resolved on purpose: hosts override a built-in type by binding a subclass (docs/host/ai/recipes.md).
            /** @var PageTypeInterface $type */
            $type = app($class);
            $this->types[$type->key()] = $type;
        }

        /** @var array<array-key, class-string> $custom */
        $custom = (array) config('tbtop-cms.pages.types', []);

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
