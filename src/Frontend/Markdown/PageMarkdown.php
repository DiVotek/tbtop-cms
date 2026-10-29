<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Markdown;

use Illuminate\Support\Facades\Log;
use Tbtop\Cms\Block\ResolvedBlock;
use Tbtop\Cms\Frontend\Seo\LlmsTxt\LlmsTxt;
use Tbtop\Cms\Frontend\Seo\LlmsTxt\LlmsTxtContacts;
use Tbtop\Cms\Frontend\Seo\LlmsTxt\LlmsTxtMarkdown;
use Tbtop\Cms\Frontend\Seo\LlmsTxt\LlmsTxtSettings;
use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\Support\PageTranslation;
use Throwable;

/**
 * A page's markdown version: YAML frontmatter from the resolved SEO, the
 * heading as the H1, summary and body, every block in order, what the page
 * type adds, the site's notes for AI and contacts, and the page's whole
 * JSON-LD graph as a `json` block — the same nodes the HTML head carries.
 */
final readonly class PageMarkdown
{
    public function __construct(
        private BlockMarkdown $blocks,
        private LlmsTxt $llms,
        private LlmsTxtSettings $settings,
        private LlmsTxtContacts $contacts,
    ) {}

    /**
     * @param  list<ResolvedBlock>  $blocks
     * @param  array<string, mixed>  $seo  SeoResolver::resolve()'s output for the page
     */
    public function render(MarkdownContext $ctx, array $blocks, array $seo, ?PageTypeInterface $type): string
    {
        $page = $ctx->page;
        $heading = PageTranslation::text($page, 'heading', $ctx->locale, $ctx->defaultLocale);
        $heading = $heading !== null && trim($heading) !== '' ? $heading : (string) ($seo['ownTitle'] ?? '');

        $parts = [
            self::frontmatter($ctx, $seo),
            Markdown::heading(1, $heading),
            $ctx->text(PageTranslation::text($page, 'summary', $ctx->locale, $ctx->defaultLocale)),
            $ctx->richtext(PageTranslation::get($page, 'body', $ctx->locale, $ctx->defaultLocale)),
        ];

        foreach ($blocks as $block) {
            $parts[] = $this->blocks->render($block, $ctx);
        }

        $parts[] = self::typeMarkdown($ctx, $type);
        $parts[] = $this->about($ctx);
        $parts[] = self::jsonLd($seo['jsonLd'] ?? []);

        return implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== '')) . "\n";
    }

    /** The page type's part; like a block's, a throw leaves it out with a warning unless `tbtop-cms.blocks.strict`. */
    private static function typeMarkdown(MarkdownContext $ctx, ?PageTypeInterface $type): string
    {
        try {
            return trim((string) $type?->toMarkdown($ctx->page, $ctx));
        } catch (Throwable $e) {
            if ((bool) config('tbtop-cms.blocks.strict', false)) {
                throw $e;
            }

            Log::warning('tbtop-cms: page type toMarkdown() failed', ['type' => $type?->key(), 'page' => $ctx->page->getKey(), 'exception' => $e]);

            return '';
        }
    }

    /** @param  array<string, mixed>  $seo */
    private static function frontmatter(MarkdownContext $ctx, array $seo): string
    {
        $lines = ['---'];

        foreach (['title' => $seo['title'] ?? '', 'description' => $seo['description'] ?? '', 'url' => $ctx->url, 'lang' => $ctx->locale, 'image' => $seo['ogImage'] ?? ''] as $key => $value) {
            if (is_string($value) && $value !== '') {
                $lines[] = $key . ': ' . self::yaml($value);
            }
        }

        $alternates = array_filter((array) ($seo['alternates'] ?? []), static fn (mixed $row): bool => is_array($row) && ($row['locale'] ?? '') !== $ctx->locale);

        if ($alternates !== []) {
            $lines[] = 'alternates:';

            foreach ($alternates as $row) {
                $lines[] = '  ' . self::yaml((string) $row['locale']) . ': ' . self::yaml((string) $row['url']);
            }
        }

        $lines[] = '---';

        return implode("\n", $lines);
    }

    /** A JSON string is a valid YAML double-quoted scalar. */
    private static function yaml(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** `## About {site}`: the editor's notes for AI and the contact list, the same text llms.txt opens with. */
    private function about(MarkdownContext $ctx): string
    {
        $intro = LlmsTxtMarkdown::block($this->settings->intro($ctx->locale, $ctx->defaultLocale));
        $contacts = implode("\n", $this->contacts->lines($ctx->locale, $ctx->defaultLocale));
        $body = implode("\n\n", array_filter([$intro, $contacts], static fn (string $part): bool => $part !== ''));

        if ($body === '') {
            return '';
        }

        return $ctx->heading(__('tbtop-cms::llms.markdown.about', ['site' => $this->llms->siteName()], $ctx->locale)) . "\n\n" . $body;
    }

    private static function jsonLd(mixed $nodes): string
    {
        if (! is_array($nodes) || $nodes === []) {
            return '';
        }

        return Markdown::fence((string) json_encode($nodes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'json');
    }
}
