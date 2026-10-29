<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tbtop\Cms\Collection\CollectionRegistry;
use Tbtop\Cms\Collection\CollectionValue;
use Tbtop\Cms\Collection\Models\Entry;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Support\PageNameSearch;

/**
 * POST {admin.prefix}/entry-search — the `collection` field's
 * picker: `{collection, search}` → `{options}` (the first 20 by the editor's
 * order, filtered by title), `{collection, ids}` → `{options}` for the picked
 * ones in the order given. Drafts are offered and marked.
 */
final class EntrySearchController
{
    private const int LIMIT = 20;

    public function __construct(
        private readonly CollectionRegistry $registry,
        private readonly Languages $languages,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $key = $request->input('collection');
        if (! is_string($key) || ! $this->registry->has($key)) {
            return response()->json(['options' => []]);
        }

        if ($request->has('ids')) {
            return response()->json(['options' => $this->byIds($key, CollectionValue::ids($request->input('ids')))]);
        }

        $search = $request->input('search');

        return response()->json(['options' => $this->search($key, is_scalar($search) ? trim((string) $search) : '')]);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{value: string, label: string, draft: bool}>
     */
    private function byIds(string $key, array $ids): array
    {
        $entries = Entry::query()->of($key)->whereKey($ids)->get()->keyBy('id');

        $options = [];
        foreach ($ids as $id) {
            $entry = $entries->get($id);
            if ($entry instanceof Entry) {
                $options[] = $this->option($entry);
            }
        }

        return $options;
    }

    /**
     * By the title column (the title field in the default locale), which the
     * model keeps on every save.
     *
     * @return list<array{value: string, label: string, draft: bool}>
     */
    private function search(string $key, string $search): array
    {
        $query = Entry::query()->of($key)->orderBy('sort_order')->orderBy('id')->limit(self::LIMIT);
        if ($search !== '') {
            PageNameSearch::applyToColumn($query, 'title', $search);
        }

        return $query->get()->map(fn (Entry $entry): array => $this->option($entry))->values()->all();
    }

    /** @return array{value: string, label: string, draft: bool} */
    private function option(Entry $entry): array
    {
        $default = $this->languages->defaultLocale();

        return [
            'value' => (string) $entry->getKey(),
            'label' => $entry->titleIn($default, $default),
            'draft' => ! $entry->isPublished(),
        ];
    }
}
