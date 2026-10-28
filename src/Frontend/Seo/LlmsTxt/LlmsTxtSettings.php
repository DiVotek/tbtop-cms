<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\LlmsTxt;

use Tbtop\Cms\Frontend\Seo\LocalizedSeo;
use Tbtop\Cms\Settings\Settings;

/**
 * The single reader of the `seo.llms_txt.*` keys the SEO settings page
 * writes on its «For AI» tab.
 */
final readonly class LlmsTxtSettings
{
    public const string ENABLED = 'seo.llms_txt.enabled';

    public const string SUMMARY = 'seo.llms_txt.summary';

    public const string INTRO = 'seo.llms_txt.intro';

    public const string TYPES = 'seo.llms_txt.types';

    public const string PER_TYPE = 'seo.llms_txt.per_type';

    public const int DEFAULT_PER_TYPE = 50;

    public const int MAX_PER_TYPE = 500;

    public function __construct(private Settings $settings) {}

    /** Off until an editor turns it on; the host config can remove the route altogether. */
    public function enabled(): bool
    {
        return (bool) config('tbtop-cms.seo.llms_txt.enabled', true)
            && (bool) $this->settings->get(self::ENABLED);
    }

    /** The blockquote under the site name: the AI description, else the fallback meta description. */
    public function summary(string $locale, string $defaultLocale): string
    {
        $own = LocalizedSeo::valueWithFallback($this->settings->get(self::SUMMARY), $locale, $defaultLocale);

        return $own !== ''
            ? $own
            : LocalizedSeo::valueWithFallback($this->settings->get('seo.default_description'), $locale, $defaultLocale);
    }

    public function intro(string $locale, string $defaultLocale): string
    {
        return LocalizedSeo::valueWithFallback($this->settings->get(self::INTRO), $locale, $defaultLocale);
    }

    /**
     * The base page types to list, or null for every one. Nothing ticked
     * means every type, so a type a host registers later is listed without
     * a visit to the settings page.
     *
     * @return list<string>|null
     */
    public function types(): ?array
    {
        $types = $this->settings->get(self::TYPES);
        $types = is_array($types) ? array_values(array_filter($types, 'is_string')) : [];

        return $types === [] ? null : $types;
    }

    public function perType(): int
    {
        $stored = $this->settings->get(self::PER_TYPE);

        return is_numeric($stored) && (int) $stored >= 1
            ? min((int) $stored, self::MAX_PER_TYPE)
            : self::DEFAULT_PER_TYPE;
    }
}
