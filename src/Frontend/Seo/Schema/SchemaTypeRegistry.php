<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema;

use InvalidArgumentException;
use Tbtop\Cms\Frontend\Seo\Schema\Types\AboutPage;
use Tbtop\Cms\Frontend\Seo\Schema\Types\Article;
use Tbtop\Cms\Frontend\Seo\Schema\Types\BlogPosting;
use Tbtop\Cms\Frontend\Seo\Schema\Types\CollectionPage;
use Tbtop\Cms\Frontend\Seo\Schema\Types\ContactPage;
use Tbtop\Cms\Frontend\Seo\Schema\Types\ItemPage;
use Tbtop\Cms\Frontend\Seo\Schema\Types\NewsArticle;
use Tbtop\Cms\Frontend\Seo\Schema\Types\WebPage;

class SchemaTypeRegistry
{
    /** The key every fallback site (an unregistered page type, or a page type declaring no schemaTypes()) resolves to. */
    public const string FALLBACK_KEY = 'WebPage';

    /** @var list<callable(): list<class-string>> */
    private static array $extensions = [];

    /** @var array<string, SchemaTypeInterface> */
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

    public function get(string $key): SchemaTypeInterface
    {
        $this->boot();

        if (! isset($this->types[$key])) {
            throw new InvalidArgumentException("Schema type [{$key}] is not registered.");
        }

        return $this->types[$key];
    }

    public function has(string $key): bool
    {
        $this->boot();

        return isset($this->types[$key]);
    }

    /** @return array<string, SchemaTypeInterface> */
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

        foreach ([WebPage::class, AboutPage::class, ContactPage::class, ItemPage::class, Article::class, NewsArticle::class, BlogPosting::class, CollectionPage::class] as $class) {
            /** @var SchemaTypeInterface $type */
            $type = app($class);
            $this->types[$type->key()] = $type;
        }

        /** @var array<array-key, class-string> $custom */
        $custom = (array) config('tbtop-cms.seo.schema_types', []);

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
        if (! is_a($class, SchemaTypeInterface::class, true)) {
            throw new InvalidArgumentException("Schema type [{$class}] must implement SchemaTypeInterface.");
        }

        /** @var SchemaTypeInterface $type */
        $type = app($class);
        $key = $type->key();

        if ($key === '') {
            throw new InvalidArgumentException("Schema type [{$class}] must return a non-empty key.");
        }

        if (isset($this->types[$key])) {
            throw new InvalidArgumentException("Schema type [{$key}] is already registered.");
        }

        $this->types[$key] = $type;
    }
}
