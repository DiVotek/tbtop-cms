<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Settings\Settings;

/**
 * Picks the title/description template a page renders through.
 *
 * Two rungs, most specific first: a template stored for the page's own type
 * (`seo.type_templates.article.title`), then the site-wide one
 * (`seo.title_template`). A page's own meta title is a third, higher rung,
 * but it lives outside a template entirely — SeoResolver resolves it before
 * asking for one, and a filled-in meta title means no template is applied at
 * all.
 *
 * A blank per-type template is not a value: it means "nothing was set for
 * this type", so the site-wide one applies. That is what lets an editor
 * clear a type's template to go back to the default, rather than being stuck
 * with an empty title.
 *
 * Templates are translatable, so a type may have a template in one locale
 * and not another; the fallback is evaluated per locale for that reason.
 */
class SeoTemplates
{
    public const string KEY_PREFIX = 'seo.type_templates';

    public function __construct(private readonly Settings $settings) {}

    public function title(string $type, string $locale): string
    {
        return $this->resolve($type, 'title', 'seo.title_template', $locale);
    }

    public function description(string $type, string $locale): string
    {
        return $this->resolve($type, 'description', 'seo.description_template', $locale);
    }

    public static function key(string $type, string $slot): string
    {
        return self::KEY_PREFIX . '.' . $type . '.' . $slot;
    }

    private function resolve(string $type, string $slot, string $siteKey, string $locale): string
    {
        $perType = LocalizedSeo::value($this->settings->get(self::key($type, $slot)), $locale);

        return $perType !== '' ? $perType : LocalizedSeo::value($this->settings->get($siteKey), $locale);
    }
}
