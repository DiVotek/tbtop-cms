<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Settings\Settings;

/**
 * The single reader of `seo.indexing_enabled`, the switch that closes the whole
 * site to search engines: robots.txt disallows crawling, the sitemap 404s, and
 * every page gets a noindex tag.
 *
 * It exists because the setting has a third state the callers kept getting
 * wrong. Settings::get() applies its $default only when the KEY IS ABSENT, so a
 * row storing a literal JSON null returns null rather than the default — and a
 * plain `(bool)` cast turns that into "indexing off". Nothing in the admin
 * makes that state look switched off, so the site would silently drop out of
 * search with the toggle still reading as on.
 *
 * An unset boolean must mean "indexing on": the three consumers have to agree,
 * because disagreeing is visible from outside — pages advertising themselves as
 * indexable while robots.txt bans the crawler that would read them.
 */
final readonly class IndexingSetting
{
    public const string KEY = 'seo.indexing_enabled';

    public function __construct(
        private Settings $settings,
    ) {}

    public function enabled(): bool
    {
        $value = $this->settings->get(self::KEY, true);

        return $value === null || (bool) $value;
    }
}
