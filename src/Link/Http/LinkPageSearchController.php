<?php

declare(strict_types=1);

namespace Tbtop\Cms\Link\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tbtop\Admin\Dsl\RelationSearchLimit;
use Tbtop\Admin\I18n\LocaleService;
use Tbtop\Admin\I18n\TranslatableValue;
use Tbtop\Cms\Page\Models\Page;

/** POST {admin.prefix}/link/page-search — relation-search protocol: `{search}` → `{options}`, `{value}` → `{option}`. */
final class LinkPageSearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->has('value')) {
            return $this->resolveByValue((string) $request->input('value', ''));
        }

        return $this->search((string) $request->input('search', ''));
    }

    private function resolveByValue(string $value): JsonResponse
    {
        $page = ctype_digit($value) ? self::query()->find($value) : null;

        return response()->json(['option' => $page === null ? null : self::toOption($page)]);
    }

    private function search(string $search): JsonResponse
    {
        $query = self::query();

        if ($search !== '') {
            $query->where('name->' . LocaleService::defaultContentLocale(), 'like', '%' . $search . '%');
        }

        $options = $query->limit(RelationSearchLimit::resolve())->get()
            ->map(static fn (Page $page): array => self::toOption($page))
            ->values()
            ->all();

        return response()->json(['options' => $options]);
    }

    /**
     * Not scoped to live() — an editor links to a page about to be published.
     *
     * @return Builder<Page>
     */
    private static function query(): Builder
    {
        return Page::query()->orderBy('sort_order')->orderBy('id');
    }

    /** @return array{value: string, label: string} */
    private static function toOption(Page $page): array
    {
        $label = TranslatableValue::pick($page->getRawOriginal('name') ?? $page->name);

        return [
            'value' => (string) $page->getKey(),
            'label' => is_scalar($label) ? (string) $label : '',
        ];
    }
}
