<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Cms\Language\Languages;

/**
 * Locales the page editor's per-locale live blocks (SerpPreview, OgPreview,
 * the SEO checklist) resolve at once, so the client can pick the active tab's
 * entry without a round-trip — see SerpPreview::build()'s docblock for why
 * resolving every locale up front beats recomputing on tab switch.
 */
final class EditorLocales
{
    /**
     * Default first — seeding the list with the default is also what keeps
     * it non-empty when the language table has no admin-active rows, so a
     * caller always has something to render.
     *
     * @return list<string>
     */
    public static function list(string $defaultLocale): array
    {
        $active = array_map(
            static fn (array $row): string => $row['locale'],
            app(Languages::class)->adminActive(),
        );

        return array_values(array_unique([$defaultLocale, ...$active]));
    }
}
