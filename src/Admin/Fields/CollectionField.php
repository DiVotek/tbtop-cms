<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Fields;

use InvalidArgumentException;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Support\FormResolver;
use Tbtop\Cms\Collection\Collection;
use Tbtop\Cms\Collection\CollectionRegistry;
use Tbtop\Cms\Collection\CollectionValue;
use Tbtop\Cms\Collection\CollectionValueValidator;
use Tbtop\Cms\Collection\Http\EntrySearchRoute;
use Tbtop\Cms\Collection\PickMode;

/**
 * `$s->collection('reviews')->of('review')` — a block field that lists a
 * collection's entries. The editor picks how: all, the latest N, those
 * related to the page being rendered, or by hand. It stores
 * `{mode, limit, ids}`; the site gets `list<{id, ...fields}>` of the
 * published ones, or one entry (or null) with single(). collections.md.
 *
 * Options are plain values rather than closures, so PrefixedField::rename()
 * replays them into a working copy.
 */
final class CollectionField extends Field
{
    protected function kind(): string
    {
        return 'collection';
    }

    /** The collection key the entries come from. Required. */
    public function of(string $collection): static
    {
        return $this->set('collection', $collection);
    }

    /** One entry or null instead of a list: the picker takes one, "latest" shows the newest. */
    public function single(bool $value = true): static
    {
        return $this->set('single', $value);
    }

    /** At most this many entries, whatever the mode. */
    public function max(int $count): static
    {
        if ($count < 1) {
            throw new InvalidArgumentException("\"{$this->name}\": max() needs at least 1.");
        }

        return $this->set('max', $count);
    }

    /**
     * The modes the editor may pick from, the first being the default.
     *
     * @param  array<array-key, PickMode|string>  $modes
     */
    public function modes(array $modes): static
    {
        $values = array_values(array_unique(array_map(
            static fn (PickMode | string $mode): string => $mode instanceof PickMode ? $mode->value : $mode,
            $modes,
        )));
        $unknown = array_diff($values, PickMode::values());
        if ($values === [] || $unknown !== []) {
            throw new InvalidArgumentException("\"{$this->name}\": modes() takes a non-empty list of " . implode(', ', PickMode::values()) . '.');
        }

        return $this->set('modes', $values);
    }

    /** One value for every locale: the entries carry their own translations. */
    #[\Override]
    public function translatable(bool $value = true): static
    {
        throw new InvalidArgumentException("\"{$this->name}\": a collection field is not translatable.");
    }

    public function collectionKey(): string
    {
        $key = $this->opts['collection'] ?? null;
        if (! is_string($key) || $key === '') {
            throw new InvalidArgumentException("\"{$this->name}\": a collection field needs ->of('<collection key>').");
        }

        return $key;
    }

    public function target(): Collection
    {
        return app(CollectionRegistry::class)->get($this->collectionKey());
    }

    public function isSingle(): bool
    {
        return ($this->opts['single'] ?? false) === true;
    }

    /** The entry cap: 1 for single(), max() when set, else none. */
    public function limitCap(): ?int
    {
        if ($this->isSingle()) {
            return 1;
        }
        $max = $this->opts['max'] ?? null;

        return is_int($max) ? $max : null;
    }

    /**
     * The declared modes (all of them by default), less "related" when the
     * collection has no `pages` field to relate by.
     *
     * @return list<PickMode>
     */
    public function allowedModes(): array
    {
        /** @var list<string> $declared */
        $declared = $this->opts['modes'] ?? PickMode::values();
        $hasRelation = $this->target()->relatedField() !== null;

        $modes = array_values(array_filter(
            array_map(PickMode::from(...), $declared),
            static fn (PickMode $mode): bool => $mode !== PickMode::Related || $hasRelation,
        ));

        return $modes === [] ? [PickMode::Manual] : $modes;
    }

    /** The resolved prop as a PHPStan type, for cms:blocks:types. */
    public function phpType(): string
    {
        $fields = (new FormResolver)->phpType($this->target()->fields(new S));
        $entry = $fields === 'array{}' ? 'array{id: int}' : 'array{id: int, ' . substr($fields, 6);

        return $this->isSingle() ? $entry . '|null' : 'list<' . $entry . '>';
    }

    /**
     * Derived on read — PrefixedField::rename() replays these into a fresh clone.
     *
     * @return list<string>
     */
    #[\Override]
    public function ruleEntries(): array
    {
        $declared = array_values(array_filter(
            parent::ruleEntries(),
            static fn (string $rule): bool => $rule !== 'nullable'
                && $rule !== 'array'
                && ! str_starts_with($rule, CollectionValueValidator::RULE),
        ));

        $isRequired = array_any($declared, static fn (string $rule): bool => str_starts_with($rule, 'required'));

        return [...($isRequired ? [] : ['nullable']), ...$declared, 'array', CollectionValueValidator::rule($this)];
    }

    #[\Override]
    public function toNode(): Node
    {
        $target = $this->target();

        $this->set('searchEndpoint', EntrySearchRoute::path());
        $this->set('allowedModes', array_map(static fn (PickMode $mode): string => $mode->value, $this->allowedModes()));
        $this->set('cap', $this->limitCap());
        $this->set('defaultLimit', min($this->limitCap() ?? PHP_INT_MAX, CollectionValue::DEFAULT_LIMIT));
        $this->set('collectionLabel', $target->label());
        $this->set('labels', self::strings('modes'));
        $this->set('limitLabel', (string) __('tbtop-cms::collections.field.limit'));
        $this->set('searchPlaceholder', (string) __('tbtop-cms::collections.field.search'));
        $this->set('emptyText', (string) __('tbtop-cms::collections.field.empty'));
        $this->set('addLabel', (string) __('tbtop-cms::collections.field.add'));
        $this->set('removeLabel', (string) __('tbtop-cms::collections.field.remove'));

        return parent::toNode();
    }

    /** @return array<string, string> */
    private static function strings(string $key): array
    {
        $group = __('tbtop-cms::collections.field.' . $key);

        return is_array($group) ? array_map(static fn (mixed $v): string => (string) $v, $group) : [];
    }
}
