<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Support;

use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Models\Page;

/**
 * Clones a Page into a new draft sitting next to the original (same parent,
 * placed last among its siblings). Kept separate from PageForm::save() —
 * duplication has no form payload to validate/guard, it's a straight copy of
 * an existing record's attributes with a fixed set of resets, so reusing the
 * form's fill/guard pipeline would be a worse fit than a dedicated writer.
 *
 * Deliberately does NOT copy: children (shallow copy only — see class docs
 * caller-side), revisions (PageRevisions observer snapshots a fresh 'create'
 * entry off the copy's own save()), slugs (PageObserver::saved() ->
 * PageSlugWriter::sync() derives them from the copy's suffixed name; a
 * collision with the original's slug is resolved by the writer's own
 * suffix-and-retry loop — see PageSlugWriter::resolve() — so no manual
 * override is needed here).
 */
final class PageDuplicator
{
    public function duplicate(Page $source): Page
    {
        // No $except list: every attribute replicate() would otherwise carry
        // over verbatim (status/published_at/unpublish_at/is_system/
        // sort_order/lock_version) is overwritten explicitly below anyway.
        $copy = $source->replicate();

        $copy->status = PageStatus::Draft;
        $copy->published_at = null;
        $copy->unpublish_at = null;
        // A copy of a system page is an ordinary page — system-page status/
        // slug locks (PageObserver::guardSystemStatus(), PageSlugWriter::
        // baseFor()) must not carry over to it.
        $copy->is_system = false;
        $copy->sort_order = $this->nextSortOrder($source->parent_id);

        // replicate() copied translation JSON verbatim; overwrite just the
        // name map with the suffixed version (title/heading/summary/body
        // stay as copied — no suffix asked for those).
        $copy->setTranslations('name', $this->suffixedName($source));

        $copy->save();

        event(new PageSaved($copy, wasCreated: true));

        return $copy;
    }

    /**
     * Appends a locale-appropriate " (copy)" suffix to every non-empty name
     * translation; an empty/absent locale value is left as-is rather than
     * becoming just the suffix on its own.
     *
     * @return array<string, string>
     */
    private function suffixedName(Page $source): array
    {
        $names = $source->getTranslations('name');

        foreach ($names as $locale => $value) {
            if ($value !== '') {
                $names[$locale] = $value . ' ' . __('tbtop-cms::pages.duplicate.name_suffix', [], $locale);
            }
        }

        return $names;
    }

    private function nextSortOrder(?int $parentId): int
    {
        $max = Page::query()->where('parent_id', $parentId)->max('sort_order');

        return $max !== null ? ((int) $max) + 1 : 0;
    }
}
