<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Errors;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Frontend\Breadcrumbs;
use Tbtop\Cms\Frontend\Errors\Support\FallbackProps;
use Tbtop\Cms\Frontend\Render\PageSpecAssembler;
use Tbtop\Cms\Frontend\Seo\SeoResolver;
use Tbtop\Cms\Page\Enums\ErrorClass;
use Tbtop\Cms\Page\Models\Page;
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
        private readonly Breadcrumbs $breadcrumbs,
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
        $locale = (string) app()->getLocale();
        $resolved = $this->blocks->resolve($page->content ?? [], $page);
        $seo = $this->seo->resolve($page, $locale, []);
        // Error pages carry no slug (see PageSlugWriter), so canonical/ogUrl
        // as computed by SeoResolver would point at a made-up '/' path —
        // suppress them outright rather than publish a wrong URL.
        $seo['canonical'] = null;
        $seo['ogUrl'] = null;
        $seo['noindex'] = true;

        return [
            'page' => [
                'id' => $page->getKey(),
                'type' => $page->type,
                'status' => $page->status->value,
                'name' => $page->getTranslation('name', $locale),
                'title' => $page->getTranslation('title', $locale),
                'heading' => $page->getTranslation('heading', $locale),
                'summary' => $page->getTranslation('summary', $locale),
                'image' => null,
            ],
            'render' => $this->assembler->assemble($resolved),
            'seo' => $seo,
            'breadcrumbs' => $this->breadcrumbs->forPage($page, $locale),
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
