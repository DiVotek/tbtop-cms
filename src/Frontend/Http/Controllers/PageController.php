<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Frontend\Breadcrumbs;
use Tbtop\Cms\Frontend\PageResolver;
use Tbtop\Cms\Frontend\Render\PageSpecAssembler;
use Tbtop\Cms\Frontend\Seo\SeoResolver;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Redirect\RedirectResolver;

class PageController
{
    public function __construct(
        private readonly PageResolver $resolver,
        private readonly Breadcrumbs $breadcrumbs,
        private readonly RedirectResolver $redirects,
        private readonly BlockContentResolver $blocks,
        private readonly PageSpecAssembler $assembler,
        private readonly SeoResolver $seo,
    ) {}

    public function __invoke(Request $request): Response | RedirectResponse
    {
        $locale = (string) app()->getLocale();
        $path = (string) $request->attributes->get('cms_path', '');

        $page = $this->resolver->resolve($path, $locale);

        if (! $page instanceof Page) {
            return $this->redirects->resolve($path, $locale, $request)
                ?? throw new NotFoundHttpException;
        }

        return Inertia::render($this->component(), $this->props($page, $locale, $path));
    }

    private function component(): string
    {
        return (string) config('tbtop-cms.render.component', 'Cms/Page');
    }

    /** @return array<string, mixed> */
    private function props(Page $page, string $locale, string $path): array
    {
        $resolved = $this->blocks->resolve($page->content ?? [], $page);

        return [
            'page' => [
                'id' => $page->getKey(),
                'type' => $page->type,
                'status' => $page->status->value,
                'name' => $page->getTranslation('name', $locale),
                'title' => $page->getTranslation('title', $locale),
                'heading' => $page->getTranslation('heading', $locale),
                'summary' => $page->getTranslation('summary', $locale),
            ],
            'render' => $this->assembler->assemble($resolved),
            'seo' => $this->seo->resolve($page, $locale),
            'breadcrumbs' => $this->breadcrumbs->forPage($page, $locale),
            'path' => $path,
        ];
    }
}
