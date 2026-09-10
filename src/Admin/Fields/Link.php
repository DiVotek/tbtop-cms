<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Fields;

use InvalidArgumentException;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Cms\Link\LinkPageSearchRoute;
use Tbtop\Cms\Link\LinkShapeValidator;
use Tbtop\Cms\Link\LinkType;

/**
 * `$s->link('cta')` — stores `{type, page, value, anchor}`, never a resolved
 * href, so a page link survives the target's slug changing. See blocks.md.
 */
final class Link extends Field
{
    protected function kind(): string
    {
        return 'link';
    }

    /**
     * Restricts the type switch, client and server alike.
     *
     * @param  array<array-key, LinkType|string>  $types
     */
    public function types(array $types): static
    {
        // Reindexed: a keyed array would serialize as a JSON object, and the
        // client's `types` is a list.
        $values = array_values(array_map(
            static fn (LinkType | string $type): string => $type instanceof LinkType ? $type->value : $type,
            $types,
        ));

        $unknown = array_diff($values, LinkType::values());

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "\"{$this->name}\": unknown link type(s) " . implode(', ', $unknown) . '.',
            );
        }

        return $this->set('types', $values);
    }

    /** Per-locale storage would make the value a locale map — the one shape LocaleFlattener must not see here. */
    #[\Override]
    public function translatable(bool $value = true): static
    {
        throw new InvalidArgumentException(
            "\"{$this->name}\": a link field is not translatable — it stores one target for every locale, "
            . 'and ResolveContext::link() localizes the page path at render time.',
        );
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
                && ! str_starts_with($rule, LinkShapeValidator::RULE),
        ));

        // Any required* rule, because PrefixedField rewrites `required` to
        // `required_unless:` — a `nullable` ahead of it would void the gate.
        $isRequired = array_any(
            $declared,
            static fn (string $rule): bool => str_starts_with($rule, 'required'),
        );

        return [...($isRequired ? [] : ['nullable']), ...$declared, 'array', $this->shapeRule()];
    }

    /** In the node's options so it survives a custom admin prefix and PrefixedField::rename(). */
    #[\Override]
    public function toNode(): Node
    {
        $this->set('searchEndpoint', LinkPageSearchRoute::path());

        return parent::toNode();
    }

    /** `link_shape` alone allows all four types; parameters narrow it to what types() declared. */
    private function shapeRule(): string
    {
        /** @var list<string> $types */
        $types = $this->opts['types'] ?? [];

        return $types === []
            ? LinkShapeValidator::RULE
            : LinkShapeValidator::RULE . ':' . implode(',', $types);
    }
}
