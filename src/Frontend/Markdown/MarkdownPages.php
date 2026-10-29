<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Markdown;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;
use Tbtop\Cms\Frontend\Seo\LlmsTxt\LlmsTxtSettings;
use Tbtop\Cms\Frontend\Seo\PageOwnSeo;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageUrl;

/**
 * Page links for agents: a page's markdown URL (`/about.md`, `/index.md`
 * for a home page, `/uk/about.md` in another locale), and the live,
 * indexable children a container page lists in its `.md`.
 */
final readonly class MarkdownPages
{
    public function __construct(
        private PageUrl $url,
        private LlmsTxtSettings $settings,
    ) {}

    /** The absolute `.md` URL for a page path in a locale. */
    public function markdownUrl(string $path, string $locale): string
    {
        $path = trim($path, '/');

        return URL::to($this->url->build($path === '' ? 'index.md' : $path . '.md', $locale));
    }

    /**
     * Where an agent should read the page: its `.md` URL while markdown is
     * on, else the page URL. Null when the page has no slug in the locale.
     */
    public function linkFor(Page $page, string $locale): ?string
    {
        $path = $page->pathFor($locale);

        if ($path === null) {
            return null;
        }

        return $this->settings->markdownEnabled() ? $this->markdownUrl($path, $locale) : URL::to($this->url->build($path, $locale));
    }

    /** `[title](url): description` with the page's own title and description; null without a slug in the locale. */
    public function item(Page $page, string $locale): ?string
    {
        $url = $this->linkFor($page, $locale);

        if ($url === null) {
            return null;
        }

        $seo = PageOwnSeo::blob($page);
        $notes = Markdown::inline(PageOwnSeo::description($page, $seo, $locale));

        return Markdown::link(PageOwnSeo::title($page, $seo, $locale), $url) . ($notes !== '' ? ': ' . Markdown::escape($notes) : '');
    }

    /**
     * The page's live, indexable, non-error children with a slug in the
     * locale, by the editor's sort order and then newest first, at most
     * `$limit` of them.
     *
     * @return list<Page>
     */
    public function children(Page $page, string $locale, int $limit): array
    {
        return Page::query()
            ->live()
            ->where('parent_id', $page->getKey())
            ->where('is_indexable', true)
            ->whereNull('error_class')
            ->whereHas('slugs', static fn (Builder $slugs): Builder => $slugs->where('locale', $locale))
            ->with('slugs')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }
}
