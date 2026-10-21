<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Fields;

use InvalidArgumentException;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Cms\Admin\Pages\Support\EditorLocales;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Link\LinkPageSearchRoute;
use Tbtop\Cms\Link\LinkShapeValidator;
use Tbtop\Cms\Link\LinkType;

/**
 * `$s->link('cta')` — stores `{type, page, value, anchor, label}`, never a
 * resolved href, so a page link survives the target's slug changing. blocks.md.
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

    /**
     * In the node's options so they survive a custom admin prefix and
     * PrefixedField::rename(); useActiveLocale/useContentLocaleConfig are not
     * exported from @tbtop/inertia-admin, so the client cannot ask for itself.
     * The per-locale row can become active-locale-aware once it exports them.
     */
    #[\Override]
    public function toNode(): Node
    {
        $default = app(Languages::class)->defaultLocale();

        $this->set('searchEndpoint', LinkPageSearchRoute::path());
        $this->set('locales', EditorLocales::list($default));
        $this->set('defaultLocale', $default);
        $this->set('labels', self::strings('types'));
        $this->set('placeholders', self::strings('placeholders'));
        $this->set('errors', self::strings('errors'));
        $this->set('anchorLabel', (string) __('tbtop-cms::link.anchor'));
        $this->set('anchorPlaceholder', (string) __('tbtop-cms::link.anchor_placeholder'));
        $this->set('pagePlaceholder', (string) __('tbtop-cms::link.page_placeholder'));
        $this->set('pageSearchLabel', (string) __('tbtop-cms::link.page_search'));
        $this->set('pageEmpty', (string) __('tbtop-cms::link.page_empty'));
        $this->set('textLabel', (string) __('tbtop-cms::link.text'));

        return parent::toNode();
    }

    /** @return array<string, string> */
    private static function strings(string $key): array
    {
        $group = __('tbtop-cms::link.' . $key);

        return is_array($group) ? array_map(static fn (mixed $v): string => (string) $v, $group) : [];
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
