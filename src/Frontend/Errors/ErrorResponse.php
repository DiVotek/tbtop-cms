<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Errors;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Frontend\Errors\Support\FallbackProps;
use Tbtop\Cms\Frontend\Form\FormActionBinder;
use Tbtop\Cms\Frontend\Render\PageSpecAssembler;
use Tbtop\Cms\Frontend\Seo\SeoResolver;
use Tbtop\Cms\Frontend\Support\CurrentPage;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Enums\ErrorClass;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\PageTranslation;
use Throwable;

/**
 * Cms::errorResponse()'s implementation — three layers, weakest-dependency
 * last: a seeded DB page renders through the normal Cms/Page pipeline, a
 * missing/unpublished page falls back to that same component with empty
 * blocks and a lang-file heading (still inside the host's chrome, since
 * there is no package Blade layout — see docs/host/ai/wiring.md), and only
 * for a 5xx does a render failure fall through once more to a static Blade
 * view with no DB/Inertia/settings dependency of its own.
 */
class ErrorResponse
{
    public function __construct(
        private readonly ErrorPageResolver $pages,
        private readonly BlockContentResolver $blocks,
        private readonly PageSpecAssembler $assembler,
        private readonly SeoResolver $seo,
        private readonly FormActionBinder $forms,
        private readonly CurrentPage $currentPage,
        private readonly Languages $languages,
    ) {}

    public function render(Request $request, int $status): Response
    {
        $errorClass = ErrorClass::fromStatus($status);

        if ($errorClass === ErrorClass::ServerError) {
            return $this->render5xx($request, $status);
        }

        return $this->renderFromPage($request, $status, $errorClass);
    }

    private function renderFromPage(Request $request, int $status, ?ErrorClass $errorClass): Response
    {
        $page = $errorClass !== null ? $this->pages->forStatus($status) : null;

        $props = $page instanceof Page
            ? $this->propsFor($page)
            : FallbackProps::build($errorClass);

        return $this->withStatus($request, Inertia::render($this->component(), $props), $status);
    }

    /** Must never throw: falls back to the static Blade view instead. */
    private function render5xx(Request $request, int $status): Response
    {
        try {
            $page = $this->pages->forStatus($status);
            $props = $page instanceof Page ? $this->propsFor($page) : FallbackProps::build(ErrorClass::ServerError);

            return $this->withStatus($request, Inertia::render($this->component(), $props), $status);
        } catch (Throwable) {
            return response()->view('tbtop-cms::errors.fallback', [], $status);
        }
    }

    /** @return array<string, mixed> */
    private function propsFor(Page $page): array
    {
        $this->currentPage->set($page);

        $locale = (string) app()->getLocale();
        ['blocks' => $blocks, 'fields' => $fields] = $this->blocks->resolveWithFields($page->content ?? [], $page);
        $resolved = $this->forms->bind($blocks, $page);
        $seo = $this->seo->resolve($page, $locale, []);
        // Error pages carry no slug (see PageSlugWriter), so canonical/ogUrl
        // as computed by SeoResolver would point at a made-up '/' path —
        // suppress them outright rather than publish a wrong URL.
        $seo['canonical'] = null;
        $seo['ogUrl'] = null;
        $seo['noindex'] = true;
        // An error page is not a node of the site: a BreadcrumbList or
        // WebPage for it would claim "Home › 404" lives at the home URL.
        $seo['jsonLd'] = [];
        $fallback = $this->languages->defaultLocale();

        return [
            'page' => [
                'id' => $page->getKey(),
                'type' => $page->type,
                'subtype' => $page->subtype,
                'status' => $page->status->value,
                'name' => PageTranslation::text($page, 'name', $locale, $fallback),
                'title' => PageTranslation::text($page, 'title', $locale, $fallback),
                'heading' => PageTranslation::text($page, 'heading', $locale, $fallback),
                'summary' => PageTranslation::text($page, 'summary', $locale, $fallback),
                'image' => null,
                'fields' => (object) $fields,
            ],
            'render' => $this->assembler->assemble($resolved),
            'seo' => $seo,
            'breadcrumbs' => [],
            'path' => null,
            'isPreview' => false,
        ];
    }

    private function withStatus(Request $request, InertiaResponse $response, int $status): Response
    {
        return $response->toResponse($request)->setStatusCode($status);
    }

    private function component(): string
    {
        return (string) config('tbtop-cms.render.component', 'Cms/Page');
    }
}
