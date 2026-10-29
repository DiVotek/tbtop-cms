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
use Tbtop\Cms\Frontend\Markdown\MarkdownContext;
use Tbtop\Cms\Frontend\Markdown\MarkdownPages;
use Tbtop\Cms\Frontend\Markdown\MarkdownRequest;
use Tbtop\Cms\Frontend\Markdown\PageMarkdown;
use Tbtop\Cms\Frontend\PageResolver;
use Tbtop\Cms\Frontend\Render\PageSpecAssembler;
use Tbtop\Cms\Frontend\Seo\ContentSignals;
use Tbtop\Cms\Frontend\Seo\IndexingSetting;
use Tbtop\Cms\Frontend\Seo\LlmsTxt\LlmsTxtSettings;
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
        private readonly PageMarkdown $markdown,
        private readonly MarkdownPages $markdownPages,
        private readonly LlmsTxtSettings $llms,
        private readonly IndexingSetting $indexing,
        private readonly ContentSignals $signals,
        private readonly MediaUrlResolver $mediaUrlResolver = new MediaUrlResolver,
    ) {}

    public function __invoke(Request $request): Response | RedirectResponse
    {
        $locale = (string) app()->getLocale();
        $path = (string) $request->attributes->get('cms_path', '');
        $markdownOn = $this->llms->markdownEnabled() && $this->indexing->enabled();
        $suffixPath = $markdownOn ? MarkdownRequest::suffixPath($path) : null;
        $wantsMarkdown = $suffixPath !== null || ($markdownOn && MarkdownRequest::accepts($request));
        $path = $suffixPath ?? $path;
        // Markdown is for agents: never a draft or a preview.
        $previewAccess = ! $wantsMarkdown && $this->hasPreviewAccess($request);

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

        if ($wantsMarkdown) {
            return $this->markdownResponse($page, $locale, $type, $source);
        }

        $props = $this->props($page, $locale, $path, $type, $source);
        $response = Inertia::render($this->component(), $props)->toResponse($request);

        // Same verdict as the meta tag, from the same computed value: a
        // crawler that reads headers and skips the body must not see a page
        // the tag calls noindex as indexable.
        if (($props['seo']['noindex'] ?? false) === true) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        if ($markdownOn) {
            $this->advertiseMarkdown($response, $path, $locale, $props['isPreview'] === true);
        }

        return $response;
    }

    private function markdownResponse(Page $page, string $locale, ?PageTypeInterface $type, ?object $source): Response
    {
        ['blocks' => $blocks, 'fields' => $fields, 'seo' => $seo] = $this->resolved($page, $locale, $type, $source);
        $subtype = $page->subtype !== null && $this->pageTypes->has($page->subtype) ? $this->pageTypes->get($page->subtype) : $type;
        $ctx = new MarkdownContext($page, $locale, $this->languages->defaultLocale(), (string) $seo['canonical'], $source, $fields, $this->markdownPages);

        $response = response()->make($this->markdown->render($ctx, $blocks, $seo, $subtype), 200, [
            'Content-Type' => MarkdownRequest::CONTENT_TYPE,
            // The HTML version is the one to index and cite.
            'Link' => '<' . $seo['canonical'] . '>; rel="canonical"',
        ]);
        $response->setVary('Accept', false);

        if ($seo['noindex'] === true) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        $signals = $this->signals->value();
        if ($signals !== null) {
            $response->headers->set('Content-Signal', $signals);
        }

        return $response;
    }

    /**
     * The HTML answer names its markdown twin and llms.txt in `Link`
     * headers, and varies on Accept so a cache never hands one to a client
     * that asked for the other.
     */
    private function advertiseMarkdown(Response $response, string $path, string $locale, bool $isPreview): void
    {
        $response->setVary('Accept', false);

        if ($isPreview) {
            return;
        }

        $links = ['<' . $this->markdownPages->markdownUrl($path, $locale) . '>; rel="alternate"; type="text/markdown"'];

        if ($this->llms->enabled()) {
            $links[] = '<' . url('llms.txt') . '>; rel="describedby"; type="text/plain"';
        }

        $response->headers->set('Link', implode(', ', $links), false);
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
        ['blocks' => $resolved, 'fields' => $fields, 'seo' => $seo] = $this->resolved($page, $locale, $type, $source);
        // Not live covers draft, scheduled (published_at in the future), and
        // expired (unpublish_at in the past): reaching this page at all with
        // any of those means the caller has preview access (PageResolver's
        // withDrafts=true branch — see hasPreviewAccess()) and should see the
        // preview affordances, same as a plain draft did before scheduling.
        $isPreview = ! $page->isLive();
        $breadcrumbs = $this->breadcrumbs->forPage($page, $locale);

        if ($isPreview) {
            $seo['noindex'] = true;
        }
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
                'body' => $this->bodyHtml($page, $locale),
                'image' => $this->resolveImage($page->image),
                // An object even when empty or keyed 0..n: the client reads it as a map.
                'fields' => (object) $fields,
            ],
            'render' => $this->assembler->assemble($resolved),
            'seo' => $seo,
            'breadcrumbs' => $breadcrumbs,
            'path' => $path,
            'isPreview' => $isPreview,
        ];
    }

    /**
     * The page's blocks (forms bound), type fields and SEO, shared by the
     * HTML and markdown answers so both carry the same JSON-LD.
     *
     * @return array{blocks: list<ResolvedBlock>, fields: array<string, mixed>, seo: array<string, mixed>}
     */
    private function resolved(Page $page, string $locale, ?PageTypeInterface $type, ?object $source): array
    {
        $content = $page->content ?? $type?->template($page) ?? [];
        ['blocks' => $blocks, 'fields' => $fields] = $this->blocks->resolveWithFields($content, $page, $source);
        $resolved = $this->forms->bind($blocks, $page);
        $ctx = $this->schemaContextFactory->make($page, $locale, $source);
        $seo = $this->seo->resolve($page, $locale, $this->breadcrumbs->ancestors($page, $locale), $ctx, $this->blockSchemaNodes($resolved, $ctx));

        return ['blocks' => $resolved, 'fields' => $fields, 'seo' => $seo];
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
