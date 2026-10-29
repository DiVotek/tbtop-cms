<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Controllers;

use Illuminate\Http\Response;
use Tbtop\Cms\Cache\CmsCache;
use Tbtop\Cms\Frontend\Seo\IndexingSetting;
use Tbtop\Cms\Frontend\Seo\LlmsTxt\LlmsTxt;
use Tbtop\Cms\Frontend\Seo\LlmsTxt\LlmsTxtSettings;
use Tbtop\Cms\Language\Languages;

/**
 * /llms.txt in the default locale; `/{locale}/llms.txt` in another frontend
 * locale reaches forLocale() through PageController, once SetLanguage has
 * picked the locale. 404 until an editor turns it on, and whenever indexing
 * is off: a site that tells crawlers to stay out does not hand agents a map
 * of itself.
 */
class LlmsTxtController
{
    private const string KEY = 'tbtop-cms:llms-txt:';

    public function __construct(
        private readonly LlmsTxt $llms,
        private readonly LlmsTxtSettings $settings,
        private readonly IndexingSetting $indexing,
        private readonly Languages $languages,
        private readonly CmsCache $cache,
    ) {}

    public function __invoke(): Response
    {
        return $this->forLocale($this->languages->defaultLocale() ?: (string) app()->getLocale());
    }

    public function forLocale(string $locale): Response
    {
        // Checked per request, outside the cache, as the sitemap does.
        if (! $this->settings->enabled() || ! $this->indexing->enabled()) {
            abort(404);
        }

        $key = self::KEY . hash('sha256', request()->getSchemeAndHttpHost()) . ':' . $locale;

        $body = (string) $this->cache->rememberLive($key, fn (): string => $this->llms->render($locale, $this->languages->defaultLocale() ?: $locale));

        return response()->make($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
