<?php

declare(strict_types=1);

namespace Tbtop\Cms\Link\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tbtop\Admin\Dsl\RelationSearchLimit;
use Tbtop\Admin\I18n\LocaleService;
use Tbtop\Admin\I18n\TranslatableValue;
use Tbtop\Cms\Admin\Pages\Support\EditorLocales;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\PageNameSearch;

/** POST {admin.prefix}/link/page-search — relation-search protocol: `{search}` → `{options}`, `{value}` → `{option}`. */
final class LinkPageSearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->has('value')) {
            $value = self::scalarInput($request, 'value');

            return $value === null
                ? response()->json(['option' => null])
                : $this->resolveByValue($value);
        }

        $search = self::scalarInput($request, 'search');

        return $search === null
            ? response()->json(['options' => []])
            : $this->search($search);
    }

    /** The input as a string, '' when absent, null when it is an array (nothing can match it). */
    private static function scalarInput(Request $request, string $key): ?string
    {
        $raw = $request->input($key);

        if ($raw === null) {
            return '';
        }

        return is_scalar($raw) ? (string) $raw : null;
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
            PageNameSearch::apply($query, $search, [LocaleService::defaultContentLocale()]);
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

    /**
     * `names` feeds the client's per-locale label placeholders.
     *
     * @return array{value: string, label: string, names: array<string, string>}
     */
    private static function toOption(Page $page): array
    {
        $raw = $page->getRawOriginal('name') ?? $page->name;
        $label = TranslatableValue::pick($raw);

        return [
            'value' => (string) $page->getKey(),
            'label' => is_scalar($label) ? (string) $label : '',
            'names' => self::names($raw),
        ];
    }

    /** @return array<string, string> */
    private static function names(mixed $raw): array
    {
        $map = TranslatableValue::asLocaleMap($raw);
        $names = [];

        // CMS languages, not tbtop-admin.content_locales: that config is only
        // populated by SyncContentLocales, which this route does not run.
        foreach (EditorLocales::list(app(Languages::class)->defaultLocale()) as $locale) {
            $text = is_array($map) ? ($map[$locale] ?? null) : null;

            if (is_string($text) && trim($text) !== '') {
                $names[$locale] = $text;
            }
        }

        return $names;
    }
}
