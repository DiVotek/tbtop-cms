<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;

class PageSlugWriter
{
    private const MAX_WRITE_ATTEMPTS = 100;

    public function __construct(private readonly Languages $languages) {}

    /**
     * @param  array<string, string>  $slugOverrides  Locale => author-chosen slug base
     *                                                (e.g. from the create/edit form's
     *                                                slug field). A non-empty override
     *                                                wins over the existing slug/name-
     *                                                derived base, for both new and
     *                                                existing rows; an empty/missing
     *                                                override for a locale keeps the
     *                                                existing slug untouched (or falls
     *                                                back to the name-derived base when
     *                                                there is no row yet).
     */
    public function sync(Page $page, array $slugOverrides = []): void
    {
        // Error pages resolve by error_class, never by path.
        if ($page->isErrorPage()) {
            return;
        }

        $changed = false;

        foreach ($this->locales() as $locale) {
            if ($this->writeRow($page, $locale, $slugOverrides[$locale] ?? '')) {
                $changed = true;
            }
        }

        if ($changed) {
            $this->cascadeChildren($page);
        }

        $page->unsetRelation('slugs');
    }

    public function backfillLocale(string $locale): void
    {
        $this->writeLevels(Page::query()->whereNull('parent_id')->get(), [$locale], changedOnly: false);
    }

    public function remove(Page $page): void
    {
        PageSlug::query()->where('page_id', $page->getKey())->delete();
    }

    private function cascadeChildren(Page $page): void
    {
        $this->writeLevels(Page::query()->where('parent_id', $page->getKey())->get(), $this->locales(), changedOnly: true);
    }

    /**
     * Writes a subtree one level at a time with a fixed number of reads per
     * level (the level's pages, their and their parents' slug rows, and the
     * rows already holding the paths they would take), instead of per page.
     * Only the writes themselves stay per row, so PageSlug model events
     * (auto redirects) keep firing.
     *
     * $changedOnly mirrors sync(): descend only below pages whose row changed,
     * and not below error pages. backfillLocale() walks every page instead.
     *
     * @param  Collection<int, Page>  $pages
     * @param  list<string>  $locales
     */
    private function writeLevels(Collection $pages, array $locales, bool $changedOnly): void
    {
        while ($pages->isNotEmpty()) {
            $rows = PageSlug::query()
                ->whereIn('page_id', [...$pages->modelKeys(), ...$pages->pluck('parent_id')->filter()->unique()->all()])
                ->whereIn('locale', $locales)
                ->get()
                ->keyBy(static fn (PageSlug $row): string => $row->page_id . '|' . $row->locale);

            $plans = [];

            foreach ($pages as $page) {
                if ($page->isErrorPage()) {
                    continue;
                }

                foreach ($locales as $locale) {
                    $existing = $rows->get($page->getKey() . '|' . $locale);
                    $parentPath = $page->parent_id === null ? '' : (string) $rows->get($page->parent_id . '|' . $locale)?->path;
                    $base = $this->baseFor($page, $locale, $existing, '');

                    $plans[] = [$page, $locale, $base, $existing, $parentPath, $this->join($parentPath, $base)];
                }
            }

            $holders = $plans === [] ? collect() : PageSlug::query()
                ->whereIn('locale', $locales)
                ->whereIn('path', array_unique(array_column($plans, 5)))
                ->get(['page_id', 'locale', 'path'])
                ->mapWithKeys(static fn (PageSlug $row): array => [$row->locale . '|' . $row->path => (int) $row->page_id]);

            $changed = [];

            foreach ($plans as [$page, $locale, $base, $existing, $parentPath, $basePath]) {
                $holder = $holders->get($locale . '|' . $basePath);
                $baseTaken = $holder !== null && $holder !== (int) $page->getKey();

                if ($this->write($page, $locale, $base, $existing, $parentPath, $baseTaken)) {
                    $changed[$page->getKey()] = true;
                }
            }

            $parentIds = $changedOnly
                ? array_keys($changed)
                : $pages->modelKeys();

            $pages = $parentIds === [] ? new Collection : Page::query()->whereIn('parent_id', $parentIds)->get();
        }
    }

    private function writeRow(Page $page, string $locale, string $slugOverride): bool
    {
        $existing = $this->findRow($page, $locale);

        return $this->write(
            $page,
            $locale,
            $this->baseFor($page, $locale, $existing, $slugOverride),
            $existing,
            $this->parentPath($page, $locale),
        );
    }

    /**
     * $baseTaken is a pre-fetched answer for the unsuffixed candidate path;
     * it is trusted only on the first attempt, every retry asks the database.
     */
    private function write(Page $page, string $locale, string $base, ?PageSlug $existing, string $parentPath, ?bool $baseTaken = null): bool
    {
        $minimumCandidate = 1;

        for ($attempt = 1; $attempt <= self::MAX_WRITE_ATTEMPTS; $attempt++) {
            [$slug, $path] = $this->resolve($page, $locale, $parentPath, $base, $minimumCandidate, $attempt === 1 ? $baseTaken : null);

            if ($existing !== null && $existing->slug === $slug && $existing->path === $path) {
                return false;
            }

            try {
                ($existing ?? new PageSlug(['page_id' => $page->getKey(), 'locale' => $locale]))
                    ->fill(['slug' => $slug, 'path' => $path])
                    ->save();

                return true;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === self::MAX_WRITE_ATTEMPTS) {
                    throw $exception;
                }

                $existing = $this->findRow($page, $locale);

                if ($existing !== null) {
                    $minimumCandidate = 1;

                    continue;
                }

                $minimumCandidate = $minimumCandidate === 1 ? 2 : $minimumCandidate + 1;
            }
        }

        return false;
    }

    private function findRow(Page $page, string $locale): ?PageSlug
    {
        return PageSlug::query()
            ->where('page_id', $page->getKey())
            ->where('locale', $locale)
            ->first();
    }

    /** @return array{0: string, 1: string} */
    private function resolve(Page $page, string $locale, string $parentPath, string $base, int $minimumCandidate, ?bool $baseTaken): array
    {
        $slug = $minimumCandidate === 1 ? $base : $base . '-' . $minimumCandidate;
        $suffix = max(2, $minimumCandidate + 1);

        while (true) {
            $path = $this->join($parentPath, $slug);

            $taken = $baseTaken ?? PageSlug::query()
                ->where('locale', $locale)
                ->where('path', $path)
                ->where('page_id', '!=', $page->getKey())
                ->exists();
            $baseTaken = null;

            if (! $taken) {
                return [$slug, $path];
            }

            $slug = $base . '-' . $suffix;
            $suffix++;
        }
    }

    /**
     * A non-empty author-chosen override (from the create/edit form's slug
     * field) always wins, whether or not a PageSlug row already exists for
     * this locale — this is what makes an existing page's slug editable.
     * An empty/missing override, or one that slugifies to '', keeps the
     * current slug untouched when a row exists, and otherwise falls back to
     * the name-derived base (slugBase()), same as before.
     *
     * System pages are excluded from all of the above: their URLs are not
     * author-editable, consistent with their type/status/parent locks (see
     * PageObserver's guardSystemStatus()/PageHierarchy), so any override is
     * ignored outright — same as passing an empty one. PageForm already
     * omits the slug field for the root system page (see generalFields()),
     * but this keeps the writer correct for any other caller that reaches
     * sync() with a stray override for a system page.
     */
    private function baseFor(Page $page, string $locale, ?PageSlug $existing, string $slugOverride): string
    {
        if ($slugOverride !== '' && ! $page->is_system) {
            $slug = Str::slug($slugOverride);

            if ($slug !== '') {
                return rtrim(mb_substr($slug, 0, 120), '-');
            }
        }

        return $existing !== null ? $existing->slug : $this->slugBase($page, $locale);
    }

    private function slugBase(Page $page, string $locale): string
    {
        if ($page->is_system && $page->parent_id === null) {
            return '';
        }

        $name = (string) ($page->getTranslation('name', $locale, false)
            ?: $page->getTranslation('name', $this->languages->defaultLocale(), false));
        $slug = Str::slug($name);

        if ($slug === '') {
            return 'page-' . $page->getKey();
        }

        return rtrim(mb_substr($slug, 0, 120), '-');
    }

    private function parentPath(Page $page, string $locale): string
    {
        if ($page->parent_id === null) {
            return '';
        }

        return (string) PageSlug::query()
            ->where('page_id', $page->parent_id)
            ->where('locale', $locale)
            ->value('path');
    }

    private function join(string $parentPath, string $slug): string
    {
        if ($parentPath === '') {
            return $slug;
        }

        if ($slug === '') {
            return $parentPath;
        }

        return $parentPath . '/' . $slug;
    }

    /** @return list<string> */
    private function locales(): array
    {
        return array_map(
            static fn (array $row): string => $row['locale'],
            $this->languages->frontendActive(),
        );
    }
}
