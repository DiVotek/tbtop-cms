<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Fields\Pages;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Block\Support\FormResolver;

/**
 * A kind of record that is not a page: reviews, team members, vacancies.
 * The schema is code, like a block's form; the records are rows in
 * `tbtop_entries`, edited on the admin's Collections page (a tab each)
 * and read on the site through a block's `collection` field or
 * Cms::collection(). collections.md.
 *
 * Registered in `config('tbtop-cms.collections')` or with
 * CollectionRegistry::extend().
 */
abstract class Collection
{
    /** Stored in each entry's `collection` column and used in admin URLs: `[a-z][a-z0-9_-]*`, at most 64 characters. */
    abstract public function key(): string;

    /** The plural name: the collection's tab on the admin's Collections page. */
    abstract public function label(): string;

    /**
     * The entry form. Every field kind a block form takes works here; a
     * `pages` field ties an entry to pages (see relatedField()). The names
     * `status` and `lock_version` are the admin's own.
     *
     * @return list<mixed>
     */
    abstract public function fields(S $s): array;

    /** The name of one entry, for the create button and the edit title. */
    public function singularLabel(): string
    {
        return $this->label();
    }

    /** The field shown as the entry's name in the admin list and the pickers; the first field by default. */
    public function titleField(): string
    {
        return $this->fieldNames()[0] ?? 'id';
    }

    /**
     * The `pages` field the "related to this page" mode filters on: a review
     * picks the services it is about, and a service's block lists its
     * reviews. The first `pages` field by default; null when there is none.
     */
    public function relatedField(): ?string
    {
        foreach (S::normalizeChildren($this->fields(new S)) as $field) {
            if ($field instanceof Pages) {
                return $field->name;
            }
        }

        return null;
    }

    /**
     * One entry as the site gets it, from its values already flattened to
     * the locale. By default every field is resolved by its kind, as a block
     * without its own resolve() is (FormResolver).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return (new FormResolver)->resolve($this->fields(new S), $input, $ctx);
    }

    /** @return list<string> */
    public function fieldNames(): array
    {
        $names = [];
        foreach (S::normalizeChildren($this->fields(new S)) as $field) {
            if ($field instanceof Field) {
                $names[] = $field->name;
            }
        }

        return $names;
    }
}
