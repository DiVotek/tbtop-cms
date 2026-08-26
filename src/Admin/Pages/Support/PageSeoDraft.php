<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Cms\Page\Models\Page;

/**
 * An unsaved copy of a page with the editor's in-flight seo_* form values
 * overlaid, so a preview resolves the real cascade against what is being
 * typed rather than against what is stored.
 *
 * replicate() drops the key, which pathFor()/alternates() need, so identity
 * is restored here; the copy must never be saved.
 */
final class PageSeoDraft
{
    /**
     * @param  array<string, string>  $deps  form values keyed "{dep}.{locale}"
     * @param  array<string, string>  $map  seo blob key => dep name
     * @param  list<string>  $locales
     */
    public static function make(Page $page, array $deps, array $map, array $locales): Page
    {
        $draft = $page->replicate();
        $draft->setRelations($page->getRelations());
        $draft->exists = true;
        $draft->setAttribute($page->getKeyName(), $page->getKey());

        $seo = is_array($page->seo) ? $page->seo : [];

        foreach ($locales as $locale) {
            foreach ($map as $key => $dep) {
                $value = $deps["{$dep}.{$locale}"] ?? null;

                if ($value !== null) {
                    $existing = is_array($seo[$key] ?? null) ? $seo[$key] : [];
                    $seo[$key] = [...$existing, $locale => $value];
                }
            }
        }

        $draft->seo = $seo;

        return $draft;
    }
}
