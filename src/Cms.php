<?php

declare(strict_types=1);

namespace Tbtop\Cms;

use Illuminate\Support\Facades\Route;
use Tbtop\Cms\Frontend\Breadcrumbs;
use Tbtop\Cms\Frontend\Http\Controllers\PageController;
use Tbtop\Cms\Frontend\Http\Middleware\SetLanguage;
use Tbtop\Cms\Frontend\PageResolver;
use Tbtop\Cms\Frontend\PageUrl;
use Tbtop\Cms\Page\Models\Page;

class Cms
{
    public function __construct(
        private readonly PageResolver $resolver,
        private readonly PageUrl $pageUrl,
        private readonly Breadcrumbs $crumbs,
    ) {}

    public function frontendRoutes(): void
    {
        /** @var list<class-string> $middleware */
        $middleware = (array) config('tbtop-cms.frontend.middleware', [SetLanguage::class]);

        Route::middleware($middleware)
            ->get('{path?}', PageController::class)
            ->where('path', '.*');
    }

    public function resolve(string $path, ?string $locale = null): ?Page
    {
        return $this->resolver->resolve($path, $locale ?? (string) app()->getLocale());
    }

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
}
