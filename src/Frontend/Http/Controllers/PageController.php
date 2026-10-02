<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tbtop\Cms\Admin\Support\CurrentAdmin;
use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\MediaUrlResolver;
use Tbtop\Cms\Block\ResolvedBlock;
use Tbtop\Cms\Frontend\Breadcrumbs;
use Tbtop\Cms\Frontend\Form\FormActionBinder;
use Tbtop\Cms\Frontend\PageResolver;
use Tbtop\Cms\Frontend\Render\PageSpecAssembler;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaContext;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaContextFactory;
use Tbtop\Cms\Frontend\Seo\SeoResolver;
use Tbtop\Cms\Frontend\Support\CurrentPage;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\NotFound\Events\PageNotFound;
use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Support\PageTranslation;
use Tbtop\Cms\Redirect\RedirectResolver;
use Tbtop\Cms\Richtext\RichtextHtmlRenderer;

class PageController
{
    public function __construct(
        private readonly PageResolver $resolver,
        private readonly Breadcrumbs $breadcrumbs,
        private readonly RedirectResolver $redirects,
        private readonly BlockContentResolver $blocks,
        private readonly PageSpecAssembler $assembler,
        private readonly SeoResolver $seo,
        private readonly FormActionBinder $forms,
        private readonly PageTypeRegistry $pageTypes,
        private readonly SchemaContextFactory $schemaContextFactory,
        private readonly BlockRegistry $blockRegistry,
        private readonly CurrentPage $currentPage,
        private readonly Languages $languages,
        private readonly RichtextHtmlRenderer $richtext,
        private readonly MediaUrlResolver $mediaUrlResolver = new MediaUrlResolver,
    ) {}

    public function __invoke(Request $request): Response | RedirectResponse
    {
        $locale = (string) app()->getLocale();
        $path = (string) $request->attributes->get('cms_path', '');
        $previewAccess = $this->hasPreviewAccess($request);

        $page = $this->resolver->resolve($path, $locale, $previewAccess);

        if (! $page instanceof Page) {
            return $this->notFound($path, $locale, $request);
        }

        $this->currentPage->set($page);

        // pages.type is unconstrained, so a page outlives its type's
        // registration; it still renders its stored content.
        $type = $this->pageTypes->has($page->type) ? $this->pageTypes->get($page->type) : null;
        $isSourceBacked = $type?->isSourceBacked() === true;

        // source() is a host database call — only source-backed types pay it.
        $source = $isSourceBacked ? $type->source($page) : null;

        // A source-backed type whose source() resolves to null (deleted,
        // unpublished on this channel) has nothing to render: same outcome
        // as an unpublished page — the sync normally retires the page first,
        // this is defense-in-depth for the gap between the two.
        if ($isSourceBacked && $source === null) {
            return $this->notFound($path, $locale, $request);
        }

        $props = $this->props($page, $locale, $path, $type, $source);
        $response = Inertia::render($this->component(), $props)->toResponse($request);

        // Same verdict as the meta tag, from the same computed value: a
        // crawler that reads headers and skips the body must not see a page
        // the tag calls noindex as indexable.
        if (($props['seo']['noindex'] ?? false) === true) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }

    private function notFound(string $path, string $locale, Request $request): RedirectResponse
    {
        $redirect = $this->redirects->resolve($path, $locale, $request);

        if ($redirect !== null) {
            return $redirect;
        }

        event(new PageNotFound($path, $locale, $request->headers->get('referer'), $request->userAgent()));

        throw new NotFoundHttpException;
    }

    /**
     * Preview access is granted to a user allowed into the cms admin
     * (CurrentAdmin::canAccess — the same rule EnsureCmsAdmin enforces on
     * panel routes, NOT mere guard authentication: the guard is often the
     * host app's regular end-user guard) or to a request carrying a valid
     * signed preview URL (see PreviewLink). Anyone else falls through to
     * the normal published-only lookup, so an unpublished path still 404s
     * (or falls back to a redirect) for them.
     */
    private function hasPreviewAccess(Request $request): bool
    {
        return CurrentAdmin::canAccess() || $request->hasValidSignature();
    }

    /** @return array<string, mixed> */
    private function props(Page $page, string $locale, string $path, ?PageTypeInterface $type, ?object $source): array
    {
        $content = $page->content ?? $type?->template($page) ?? [];
        $resolved = $this->forms->bind($this->blocks->resolve($content, $page, $source), $page);
        // Not live covers draft, scheduled (published_at in the future), and
        // expired (unpublish_at in the past): reaching this page at all with
        // any of those means the caller has preview access (PageResolver's
        // withDrafts=true branch — see hasPreviewAccess()) and should see the
        // preview affordances, same as a plain draft did before scheduling.
        $isPreview = ! $page->isLive();
        $breadcrumbs = $this->breadcrumbs->forPage($page, $locale);
        $ctx = $this->schemaContextFactory->make($page, $locale, $source);
        $blockNodes = $this->blockSchemaNodes($resolved, $ctx);
        $seo = $this->seo->resolve($page, $locale, $this->breadcrumbs->ancestors($page, $locale), $ctx, $blockNodes);

        if ($isPreview) {
            $seo['noindex'] = true;
        }

        return [
            'page' => [
                'id' => $page->getKey(),
                'type' => $page->type,
                'status' => $page->status->value,
                'name' => $page->getTranslation('name', $locale),
                'title' => $page->getTranslation('title', $locale),
                'heading' => $page->getTranslation('heading', $locale),
                'summary' => $page->getTranslation('summary', $locale),
                'body' => $this->bodyHtml($page, $locale),
                'image' => $this->resolveImage($page->image),
            ],
            'render' => $this->assembler->assemble($resolved),
            'seo' => $seo,
            'breadcrumbs' => $breadcrumbs,
            'path' => $path,
            'isPreview' => $isPreview,
        ];
    }

    /** The Body as HTML in the active locale, falling back to the default locale; '' when empty. */
    private function bodyHtml(Page $page, string $locale): string
    {
        return $this->richtext->render(PageTranslation::get($page, 'body', $locale, $this->languages->defaultLocale()));
    }

    private function component(): string
    {
        return (string) config('tbtop-cms.render.component', 'Cms/Page');
    }

    /**
     * A block's own schema.org node, one per block whose Block::toSchema()
     * returns one — a container block collects its children's nodes itself,
     * this does not walk into nested block content.
     *
     * @param  list<ResolvedBlock>  $resolved
     * @return list<array<string, mixed>>
     */
    private function blockSchemaNodes(array $resolved, SchemaContext $ctx): array
    {
        $nodes = [];

        foreach ($resolved as $block) {
            $node = $this->blockRegistry->get($block->kind)->toSchema($block->props, $ctx);

            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    // image stores a media id, not a URL; resolve() already returns null for
    // an empty/missing/deleted id, so no extra guard is needed here.
    private function resolveImage(?string $image): ?string
    {
        return $image !== null && $image !== '' ? $this->mediaUrlResolver->resolve($image) : null;
    }
}
