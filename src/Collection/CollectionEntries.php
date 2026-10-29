<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection;

use Illuminate\Support\Facades\Log;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\MediaUrlResolver;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Block\Support\LocaleFlattener;
use Tbtop\Cms\Block\Support\TranslatablePaths;
use Tbtop\Cms\Cache\CmsCache;
use Tbtop\Cms\Collection\Models\Entry;
use Tbtop\Cms\Language\Languages;
use Throwable;

/**
 * The published entries of a collection as the site gets them: every entry
 * resolved for one locale, in the editor's order, cached whole under the
 * content version (any entry, page, settings or language save bumps it).
 * Filtering — latest, related to a page, picked by hand — happens on the
 * cached list in memory (EntryList), so a collection is one cache read per
 * request and locale. Meant for hundreds of entries, not tens of thousands.
 */
final class CollectionEntries
{
    /** @var array<string, list<array<string, mixed>>> per-request memo by `{key}:{locale}` */
    private array $memo = [];

    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly CmsCache $cache,
        private readonly Languages $languages,
        private readonly MediaUrlResolver $media,
    ) {}

    /** Throws for an unregistered key, as BlockRegistry::get() does: a typo in code should fail loudly. */
    public function of(string $key, ?string $locale = null): EntryList
    {
        $collection = $this->registry->get($key);
        $locale ??= (string) app()->getLocale();
        $memoKey = "{$key}:{$locale}";

        $this->memo[$memoKey] ??= $this->cache->remember(
            "collection:{$memoKey}",
            fn (): array => $this->load($collection, $locale),
        );

        return new EntryList($this->memo[$memoKey], $collection->relatedField());
    }

    /** Dropped after a save in the same request, as SharedBlocks::forget(). */
    public function forget(): void
    {
        $this->memo = [];
    }

    /** @return list<array<string, mixed>> */
    private function load(Collection $collection, string $locale): array
    {
        $fallback = $this->languages->defaultLocale();
        $locales = array_column($this->languages->all(), 'locale');
        $translatable = TranslatablePaths::of($collection->fields(new S));
        $ctx = new ResolveContext($locale, $fallback, null, $this->media);

        $entries = Entry::query()->of($collection->key())->published()
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $out = [];
        foreach ($entries as $entry) {
            try {
                $input = LocaleFlattener::flattenData($entry->data, $locale, $fallback, $locales, $translatable);
                $out[] = ['id' => (int) $entry->getKey(), ...$collection->resolve($input, $ctx)];
            } catch (Throwable $e) {
                // As a failing block: logged, and the rest of the collection still renders.
                Log::warning('collection.resolve_failed', ['collection' => $collection->key(), 'entry' => $entry->getKey(), 'error' => $e->getMessage()]);
            }
        }

        return $out;
    }
}
