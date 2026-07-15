<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Support\Facades\Route;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;

/**
 * Builds a "which pages use each block kind" index in a single pass over the
 * pages table, so the showcase can show usage without one query per block.
 * Only Page.content is scanned — templates and layout slots are out of scope.
 */
class BlockUsage
{
    /**
     * @return array<string, list<array{name: string, url: string|null}>>
     *                                                                    keyed by block kind
     */
    public static function index(): array
    {
        $default = app(Languages::class)->defaultLocale();
        $hasEdit = Route::has('tbtop.cms.page-edit-page');

        /** @var array<string, list<array{name: string, url: string|null}>> $index */
        $index = [];

        foreach (Page::query()->orderBy('sort_order')->orderBy('id')->get() as $page) {
            $entry = null;
            foreach (self::kindsIn($page->content) as $kind) {
                $entry ??= [
                    'name' => $page->getTranslation('name', $default, false) ?: ('#' . $page->getKey()),
                    'url' => $hasEdit ? route('tbtop.cms.page-edit-page', ['page' => $page->getKey()]) : null,
                ];
                $index[$kind][] = $entry;
            }
        }

        return $index;
    }

    /**
     * Distinct block kinds present in a page's content envelope.
     *
     * @return list<string>
     */
    private static function kindsIn(mixed $content): array
    {
        if (! is_array($content)) {
            return [];
        }

        $kinds = [];
        foreach ($content as $block) {
            $kind = is_array($block) ? ($block['kind'] ?? null) : null;
            if (is_string($kind) && $kind !== '' && ! in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }
}
