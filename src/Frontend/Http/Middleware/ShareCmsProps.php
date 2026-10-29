<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Cms\Block\ResolvedBlock;
use Tbtop\Cms\Cache\CmsCache;
use Tbtop\Cms\Frontend\Form\FormActionBinder;
use Tbtop\Cms\Frontend\Support\CurrentPage;
use Tbtop\Cms\Frontend\Support\HomePage;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Layout\LayoutResolver;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Settings\SiteContacts;

class ShareCmsProps
{
    public function __construct(
        private readonly Languages $languages,
        private readonly Settings $settings,
        private readonly LayoutResolver $layoutResolver,
        private readonly CmsCache $cache,
        private readonly FormActionBinder $forms,
        private readonly CurrentPage $currentPage,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $rootView = config('tbtop-cms.render.root_view');

        if (is_string($rootView) && $rootView !== '') {
            Inertia::setRootView($rootView);
        }

        Inertia::share('cms', fn (): array => [
            'settings' => [...$this->settings->forFrontend(), ...$this->contacts()],
            'locale' => (string) app()->getLocale(),
            'defaultLocale' => $this->languages->defaultLocale(),
            'languages' => $this->frontendLanguages(),
            'layout' => $this->resolveLayout(),
            // What CmsRenderer's showErrors is wired to: a developer console line, never anything on the page.
            'debug' => (bool) config('app.debug'),
            // Fixed UI text the stock CmsPage renders, translated for the request locale.
            'strings' => [
                'previewBanner' => __('tbtop-cms::pages.preview.banner'),
            ],
        ]);

        Inertia::share('errors', Inertia::always($this->validationErrors($request)));

        return $next($request);
    }

    /**
     * The contacts under the settings keys they were stored at before the
     * Phones, Emails and Socials collections took over, in the same row
     * shapes, so a template reading `cms.settings['site.contacts.phones']`
     * keeps working.
     *
     * @return array<string, list<array<string, string|null>>>
     */
    private function contacts(): array
    {
        $contacts = app(SiteContacts::class);

        return [
            SiteContacts::PHONES => array_map(static fn (string $number): array => ['number' => $number], $contacts->phones()),
            SiteContacts::EMAILS => array_map(static fn (string $address): array => ['address' => $address], $contacts->emails()),
            SiteContacts::SOCIALS => $contacts->socials(),
        ];
    }

    /** @return list<array{locale: string, name: string, is_default: bool}> */
    private function frontendLanguages(): array
    {
        return array_map(
            static fn (array $row): array => [
                'locale' => $row['locale'],
                'name' => $row['name'],
                'is_default' => $row['is_default'],
            ],
            $this->languages->frontendActive(),
        );
    }

    /**
     * Cached here rather than inside LayoutResolver: this middleware is the
     * service's only frontend caller (admin's layout editor loads Layout
     * models directly), so caching at the call site keeps admin reads live
     * without LayoutResolver needing to know which caller it's serving.
     *
     * Keyed and cached per locale, as plain arrays rather than ResolvedBlock
     * objects — a host whose cache.serializable_classes excludes ResolvedBlock
     * (e.g. `false`) makes the file/database stores turn a cached object into
     * __PHP_Incomplete_Class on the next read. `action`
     * is bound after the cache read, per request, since it depends on the
     * current page rather than the (page-independent) layout content.
     * rememberLive(): page links in layout blocks resolve against the live
     * scope, so a cached entry must not outlive a schedule transition.
     *
     * @return array<string, list<ResolvedBlock>>
     */
    private function resolveLayout(): array
    {
        $locale = (string) app()->getLocale();

        /** @var array<string, list<array<string, mixed>>> $cached */
        $cached = $this->cache->rememberLive(
            'layout:' . $locale,
            fn (): array => array_map(
                static fn (array $blocks): array => array_map(static fn (ResolvedBlock $block): array => $block->toArray(), $blocks),
                $this->layoutResolver->resolve(),
            ),
        );

        return $this->bindForms(array_map(
            static fn (array $blocks): array => array_map(
                ResolvedBlock::fromArray(...),
                $blocks,
            ),
            $cached,
        ));
    }

    /**
     * The current page if one is on screen, else the home page — decision
     * for this fallback lives in the docs (docs/host/ai/blocks.md). Neither
     * present (host route with no CMS page, no seeded home) leaves layout
     * form blocks unbound rather than pointing at a made-up page.
     *
     * @param  array<string, list<ResolvedBlock>>  $slots
     * @return array<string, list<ResolvedBlock>>
     */
    private function bindForms(array $slots): array
    {
        $page = $this->currentPage->get() ?? HomePage::find();

        if (! $page instanceof Page) {
            return $slots;
        }

        return array_map(fn (array $blocks): array => $this->forms->bind($blocks, $page), $slots);
    }

    private function validationErrors(Request $request): object
    {
        if (! $request->hasSession()) {
            return (object) [];
        }

        $bag = $request->session()->get('errors');

        if (! $bag instanceof ViewErrorBag) {
            return (object) [];
        }

        return (object) array_map(
            static fn (array $messages): string => $messages[0],
            $bag->getBag('default')->getMessages(),
        );
    }
}
