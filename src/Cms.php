<?php

declare(strict_types=1);

namespace Tbtop\Cms;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Cms\Form\Forms;
use Tbtop\Cms\Frontend\Breadcrumbs;
use Tbtop\Cms\Frontend\Errors\ErrorResponse;
use Tbtop\Cms\Frontend\Http\Controllers\FormSubmitController;
use Tbtop\Cms\Frontend\Http\Controllers\PageController;
use Tbtop\Cms\Frontend\Http\Controllers\RobotsController;
use Tbtop\Cms\Frontend\Http\Controllers\SitemapController;
use Tbtop\Cms\Frontend\Http\Middleware\EnableSsr;
use Tbtop\Cms\Frontend\Http\Middleware\InjectGtm;
use Tbtop\Cms\Frontend\Http\Middleware\SetLanguage;
use Tbtop\Cms\Frontend\Http\Middleware\ShareCmsProps;
use Tbtop\Cms\Frontend\PageResolver;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageUrl;
use Tbtop\Cms\Page\Refs\PageRefIndex;
use Tbtop\Cms\Page\Refs\PageRefList;

class Cms
{
    public function __construct(
        private readonly PageResolver $resolver,
        private readonly PageUrl $pageUrl,
        private readonly Breadcrumbs $crumbs,
        private readonly ErrorResponse $errors,
        private readonly Forms $forms,
        private readonly PageRefIndex $refs,
        private readonly PageRefList $refList,
    ) {}

    /**
     * Must be called from a `web`-middleware-group route file (e.g. `routes/web.php`) —
     * this does not wrap the group in `web` middleware itself; CSRF/session depend on the host.
     */
    public function frontendRoutes(): void
    {
        /** @var list<class-string> $middleware */
        $middleware = (array) config('tbtop-cms.frontend.middleware', [SetLanguage::class]);

        Route::middleware([...$middleware, EnableSsr::class, InjectGtm::class, ShareCmsProps::class])->group(function (): void {
            Route::post('_cms/forms/{locale}/{page}/{block}', FormSubmitController::class)
                ->where('page', '[0-9]+')
                ->middleware('throttle:' . config('tbtop-cms.forms.throttle', '10,1'))
                ->name('tbtop-cms.form.submit');

            $this->seoRoutes();

            Route::get('{path?}', PageController::class)->where('path', '.*')->name('tbtop-cms.page');
        });
    }

    private function seoRoutes(): void
    {
        Route::get('robots.txt', RobotsController::class)->name('tbtop-cms.robots');

        if (! config('tbtop-cms.seo.sitemap.enabled', true)) {
            return;
        }

        Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('tbtop-cms.sitemap');
        // Declared before the catch-all page route (frontendRoutes() calls this
        // first), so a page whose slug happens to be "sitemap-1.xml" cannot
        // shadow a chunk. Chunks are numbered from 1 with no leading zeros;
        // the length cap keeps the value inside an int, so an oversized number
        // falls through to a 404 instead of a TypeError on chunk(int).
        Route::get('sitemap-{chunk}.xml', [SitemapController::class, 'chunk'])
            ->where('chunk', '[1-9][0-9]{0,8}')
            ->name('tbtop-cms.sitemap.chunk');
    }

    public function resolve(string $path, ?string $locale = null): ?Page
    {
        return $this->resolver->resolve($path, $locale ?? (string) app()->getLocale());
    }

    /**
     * The page's root-relative frontend path in a locale (`/about`,
     * `/uk/about` for a non-default locale). Wrap it in url() for an
     * absolute URL.
     */
    public function url(Page $page, ?string $locale = null): string
    {
        $locale ??= (string) app()->getLocale();

        return $this->pageUrl->build((string) $page->pathFor($locale), $locale);
    }

    /** @return list<array{label: string, path: string, url: string}> */
    public function breadcrumbs(Page $page, ?string $locale = null): array
    {
        return $this->crumbs->forPage($page, $locale ?? (string) app()->getLocale());
    }

    /**
     * The live pages whose page-type `pages` field `$field` picks `$page`,
     * optionally only those whose type or subtype is `$from`; by sort_order,
     * then name. Empty for a null page, so `$ctx->page` passes as is.
     *
     * @return list<Page>
     */
    public function referencedBy(?Page $page, string $field, ?string $from = null, ?string $locale = null): array
    {
        if ($page === null) {
            return [];
        }

        return $this->refs->sources($page, $field, $from, $locale ?? (string) app()->getLocale());
    }

    /**
     * referencedBy() as the `{id, name, url}` list a `pages` field resolves
     * to; a page with no name or no slug in the locale is left out.
     *
     * @return list<array{id: string, name: string, url: string}>
     */
    public function referencedByLinks(?Page $page, string $field, ?string $from = null, ?string $locale = null): array
    {
        $locale ??= (string) app()->getLocale();

        return $this->refList->items($this->referencedBy($page, $field, $from, $locale), $locale);
    }

    /** Every HasForm block by name, e.g. `forms()->props('ServiceForm', $page)` for a template-rendered form. */
    public function forms(): Forms
    {
        return $this->forms;
    }

    /**
     * Always returns a response: the seeded DB page for this status class,
     * or (4xx/5xx alike) a chrome-fallback render when it's missing, or (5xx
     * only) a static Blade view when rendering itself throws. Works whether
     * or not `errors.handle` auto-registered anything — see ErrorPagesWiring::wireErrorPages().
     */
    public function errorResponse(Request $request, int $status): Response
    {
        return $this->errors->render($request, $status);
    }
}
