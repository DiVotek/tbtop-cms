<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Fields;

use InvalidArgumentException;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Fields\Select;
use Tbtop\Admin\Dsl\Fields\Text;
use Tbtop\Admin\I18n\LocaleService;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Refs\PageIds;
use Tbtop\Cms\Page\Refs\PageRefList;
use Tbtop\Cms\Page\Refs\PageRefRule;
use Tbtop\Cms\Page\Refs\PageTypeFilter;
use Tbtop\Cms\Page\Support\PageNameSearch;
use Tbtop\Cms\Page\Support\PageQuickCreate;

/**
 * `$s->pages('services')->of('article', 'legal_service')` — a searchable
 * multiple select of pages that stores their ids as strings, in the order
 * picked. On the wire it is a plain async `select`: the admin client already
 * renders it. The site gets `list<{id, name, url}>` of the live ones
 * (FormResolver); a page type's field also feeds the reverse index behind
 * Cms::referencedBy(). pages.md.
 *
 * Its closures are built in the constructor from the options, so
 * PrefixedField::rename(), which replays options into `$field::make()`, gets
 * a working copy.
 */
final class Pages extends Select
{
    private const string TYPES = 'pageTypes';

    private const string CREATES = 'createsPages';

    private const int SEARCH_LIMIT = 20;

    private ?int $exceptPageId = null;

    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->multiple()
            ->searchable()
            ->query(fn (array $deps, string $search): array => $this->search($search))
            ->resolveUsing(static fn (string $value): ?string => self::labelOf($value));
    }

    /** Accept pages of this type (and subtype). Call again to accept more pairs; never called: any page. */
    public function of(string $type, ?string $subtype = null): static
    {
        /** @var list<string> $pairs */
        $pairs = $this->opts[self::TYPES] ?? [];
        $pair = PageTypeFilter::pair($type, $subtype);

        return $this->set(self::TYPES, in_array($pair, $pairs, true) ? $pairs : [...$pairs, $pair]);
    }

    public function max(int $count): static
    {
        if ($count < 1) {
            throw new InvalidArgumentException("\"{$this->name}\": max() needs at least 1.");
        }

        return $this->rules('max:' . $count);
    }

    /**
     * "+ Create" in the select: the typed name becomes a published page of
     * the one of() type, under the parent of the newest page of that type
     * (PageQuickCreate). With $fields and $using it is Select::creatable()
     * unchanged.
     *
     * @param  list<Field>  $fields
     */
    #[\Override]
    public function creatable(array $fields = [], ?callable $using = null): static
    {
        if ($using !== null) {
            return parent::creatable($fields, $using);
        }

        $this->opts[self::CREATES] = true;

        return parent::creatable(
            [Text::make('name')->label(__('tbtop-cms::pages.fields.name'))->required()->rules('max:255')],
            fn (array $validated): array => PageQuickCreate::create($this->typeFilter(), (string) $validated['name'], LocaleService::currentLocale()),
        );
    }

    /** PrefixedField::rename() replays options; the create closure is rebuilt from the flag, it is not one. */
    #[\Override]
    public function set(string $key, mixed $value): static
    {
        if ($key === self::CREATES && $value === true) {
            return $this->creatable();
        }

        return parent::set($key, $value);
    }

    /** Leaves the page being edited out of the search and rejects it on save — a page does not reference itself. Server-only. */
    public function exceptPage(int $id): static
    {
        $this->exceptPageId = $id;

        return $this;
    }

    /** One id for every locale; a per-locale map would never resolve. */
    #[\Override]
    public function translatable(bool $value = true): static
    {
        throw new InvalidArgumentException("\"{$this->name}\": a pages field is not translatable.");
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
                && ! str_starts_with($rule, PageRefRule::RULE),
        ));

        $isRequired = array_any($declared, static fn (string $rule): bool => str_starts_with($rule, 'required'));

        return [...($isRequired ? [] : ['nullable']), ...$declared, PageRefRule::except($this->exceptPageId)];
    }

    /** The type/subtype pairs of(): which pages the search offers. */
    public function typeFilter(): PageTypeFilter
    {
        $pairs = $this->opts[self::TYPES] ?? [];

        return PageTypeFilter::of(is_array($pairs) ? $pairs : []);
    }

    /**
     * Drafts included: an editor may reference a page before publishing it.
     *
     * @return list<array{value: string, label: string}>
     */
    private function search(string $search): array
    {
        $locale = LocaleService::currentLocale();
        $query = $this->typeFilter()->apply(Page::query());

        if ($this->exceptPageId !== null) {
            $query->whereKeyNot($this->exceptPageId);
        }

        $search = trim($search);
        if ($search !== '') {
            PageNameSearch::apply($query, $search, [$locale, LocaleService::defaultContentLocale()]);
        }

        return $query->orderBy('sort_order')->orderBy('id')->limit(self::SEARCH_LIMIT)->get()
            ->map(static fn (Page $page): array => [
                'value' => (string) $page->getKey(),
                'label' => PageRefList::nameOf($page, $locale) ?? '#' . $page->getKey(),
            ])
            ->values()
            ->all();
    }

    private static function labelOf(string $value): ?string
    {
        $id = PageIds::one($value);
        $page = $id === null ? null : Page::query()->find($id);

        return $page === null ? null : (PageRefList::nameOf($page, LocaleService::currentLocale()) ?? '#' . $page->getKey());
    }
}
