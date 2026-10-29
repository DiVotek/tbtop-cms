<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection;

use InvalidArgumentException;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\StructureWalk;
use Tbtop\Cms\Admin\Fields\CollectionField;
use Tbtop\Cms\Admin\Fields\Pages;
use Tbtop\Cms\Collection\BuiltIn\Emails;
use Tbtop\Cms\Collection\BuiltIn\Phones;
use Tbtop\Cms\Collection\BuiltIn\Socials;

/**
 * The collections a site has: the built-in Phones, Emails and Socials, then
 * `config('tbtop-cms.collections')`, then extend(). Mirrors PageTypeRegistry.
 */
class CollectionRegistry
{
    /** Field names the entry form owns. */
    public const array RESERVED_FIELDS = ['id', 'status', 'lock_version'];

    private const string KEY_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';

    /** @var list<callable(): list<class-string>> */
    private static array $extensions = [];

    /** @var array<string, Collection> */
    private array $collections = [];

    private bool $booted = false;

    /**
     * @param  callable(): list<class-string>  $extension  invoked once, on the registry's first use
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

    public function get(string $key): Collection
    {
        $this->boot();

        if (! isset($this->collections[$key])) {
            throw new InvalidArgumentException("Collection [{$key}] is not registered.");
        }

        return $this->collections[$key];
    }

    public function has(string $key): bool
    {
        $this->boot();

        return isset($this->collections[$key]);
    }

    /** @return array<string, Collection> */
    public function all(): array
    {
        $this->boot();

        return $this->collections;
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        // Latched first: a collection's fields() may build a `collection`
        // field, whose toNode() asks this registry for the target.
        $this->booted = true;

        // Container-resolved, as the built-in page types: a host overrides one by binding a subclass.
        foreach ([Phones::class, Emails::class, Socials::class] as $class) {
            $this->register($class);
        }

        /** @var array<array-key, class-string> $custom */
        $custom = (array) config('tbtop-cms.collections', []);
        foreach ($custom as $class) {
            $this->register($class);
        }

        foreach (self::$extensions as $extension) {
            foreach ($extension() as $class) {
                $this->register($class);
            }
        }
    }

    /** @param  class-string  $class */
    private function register(string $class): void
    {
        if (! is_a($class, Collection::class, true)) {
            throw new InvalidArgumentException("Collection [{$class}] must extend " . Collection::class . '.');
        }

        /** @var Collection $collection */
        $collection = app($class);
        $key = $collection->key();

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Collection [{$class}] key \"{$key}\" must match [a-z][a-z0-9_-]*, at most 64 characters.");
        }

        if (isset($this->collections[$key])) {
            throw new InvalidArgumentException("Collection [{$key}] is already registered.");
        }

        // The admin names a collection's table `entries_{key}` with - as _, so team-a and team_a would share it.
        foreach (array_keys($this->collections) as $known) {
            if (str_replace('-', '_', $known) === str_replace('-', '_', $key)) {
                throw new InvalidArgumentException("Collection [{$key}] clashes with [{$known}]: keys may not differ only by - and _.");
            }
        }

        self::assertFields($collection);
        $this->collections[$key] = $collection;
    }

    private static function assertFields(Collection $collection): void
    {
        $fields = S::normalizeChildren($collection->fields(new S));
        foreach ($fields as $field) {
            if ($field instanceof Field && in_array($field->name, self::RESERVED_FIELDS, true)) {
                throw new InvalidArgumentException("Collection [{$collection->key()}]: the field name \"{$field->name}\" is reserved.");
            }
        }
        // A typo in an overridden titleField()/relatedField() would leave every entry untitled or unrelated, silently.
        $names = $collection->fieldNames();
        if ($names !== [] && ! in_array($collection->titleField(), $names, true)) {
            throw new InvalidArgumentException("Collection [{$collection->key()}]: the title field \"{$collection->titleField()}\" is not a field of its form.");
        }
        $related = $collection->relatedField();
        if ($related !== null && ! in_array($related, self::pagesFields($fields), true)) {
            throw new InvalidArgumentException("Collection [{$collection->key()}]: the related field \"{$related}\" is not a pages field of its form.");
        }
        // An entry is resolved once per locale and cached whole; an entry picking other entries, at any depth, would nest those caches.
        foreach ($fields as $root) {
            $nested = StructureWalk::find($root, static fn (mixed $node): bool => $node instanceof CollectionField);
            if ($nested instanceof CollectionField) {
                throw new InvalidArgumentException("Collection [{$collection->key()}]: a collection field cannot hold another collection field (\"{$nested->name}\").");
            }
        }
    }

    /**
     * @param  list<mixed>  $fields
     * @return list<string>
     */
    private static function pagesFields(array $fields): array
    {
        $names = [];
        foreach ($fields as $field) {
            if ($field instanceof Pages) {
                $names[] = $field->name;
            }
        }

        return $names;
    }
}
