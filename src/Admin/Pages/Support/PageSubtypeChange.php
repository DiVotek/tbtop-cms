<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\Cond;
use Tbtop\Admin\I18n\LocaleService;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Refs\PageRefList;
use Tbtop\Cms\Page\Refs\StrandedReferrers;

/**
 * The edit form's subtype picks that would strand pages referencing this one
 * (StrandedReferrers), each with the condition it holds under and the confirm
 * copy naming those pages. The options are known when the form renders, so
 * every pick gets its own text up front — the admin's confirm text is fixed
 * per action, like PageLeafType::flip(). Only a pick that keeps the type:
 * a type change is PageLeafType's to confirm.
 */
final class PageSubtypeChange
{
    /** Names listed in the confirm; the rest are counted. */
    private const int LISTED = 10;

    /** @return list<array{when: Cond, title: string, description: string}> */
    public static function warnings(Page $page): array
    {
        $stored = (string) ($page->subtype ?? '');
        $picks = array_values(array_filter(
            array_column(PageFormOptions::subtypes((string) $page->type), 'value'),
            static fn (string $value): bool => $value !== $stored,
        ));
        if ($picks === []) {
            return [];
        }

        $out = [];
        foreach (app(StrandedReferrers::class)->bySubtype($page, $picks) as $pick => $pages) {
            $out[] = [
                'when' => Cond::all(
                    Cond::eq('type', $page->type),
                    $pick === '' ? Cond::empty('subtype') : Cond::eq('subtype', $pick),
                ),
                'title' => __('tbtop-cms::pages.subtype_change.title'),
                'description' => __('tbtop-cms::pages.subtype_change.description', [
                    'count' => count($pages),
                    'pages' => self::names($pages),
                ]),
            ];
        }

        return $out;
    }

    /** @param  list<Page>  $pages */
    private static function names(array $pages): string
    {
        $locale = LocaleService::currentLocale();
        $names = array_map(
            static fn (Page $page): string => PageRefList::nameOf($page, $locale) ?? '#' . $page->getKey(),
            array_slice($pages, 0, self::LISTED),
        );
        $rest = count($pages) - count($names);

        return implode(', ', $names) . ($rest > 0 ? ' ' . __('tbtop-cms::pages.subtype_change.more', ['count' => $rest]) : '');
    }
}
