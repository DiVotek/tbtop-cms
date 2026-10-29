<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Markdown;

use Tbtop\Cms\Frontend\Seo\LlmsTxt\LlmsTxtSettings;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Richtext\RichtextMarkdownRenderer;

/**
 * What Block::toMarkdown() and PageTypeInterface::toMarkdown() get: the
 * page and locale, the page's canonical URL, the page type's source() and
 * fields, and helpers that escape stored text so a host does not assemble
 * markdown by hand. Read other pages through the `Cms` facade
 * (`referencedBy()`, `collection()`), as a block's resolve() does.
 */
final readonly class MarkdownContext
{
    /** @param  array<string, mixed>  $fields  the page's resolved type fields (`page.fields`) */
    public function __construct(
        public Page $page,
        public string $locale,
        public string $defaultLocale,
        public string $url,
        public ?object $source = null,
        public array $fields = [],
        private ?MarkdownPages $pages = null,
        private RichtextMarkdownRenderer $richtext = new RichtextMarkdownRenderer,
    ) {}

    public function field(string $name): mixed
    {
        return $this->fields[$name] ?? null;
    }

    /** Plain text, escaped: blank lines split paragraphs, single newlines stay line breaks. */
    public function text(?string $text): string
    {
        return $text === null ? '' : Markdown::text($text);
    }

    /** Characters with markdown meaning, backslash-escaped, for text inside your own markup. */
    public function escape(string $text): string
    {
        return Markdown::escape($text);
    }

    /** `## text` (escaped); '' for empty text. The page heading is the only H1. */
    public function heading(string $text, int $level = 2): string
    {
        return Markdown::heading($level, $text);
    }

    /** `[text](url)`, the URL made absolute. */
    public function link(string $text, string $url): string
    {
        return Markdown::link($text, $url);
    }

    /**
     * A bulleted list; each item is taken as markdown, so escape plain text
     * with escape() or build it with link().
     *
     * @param  list<string>  $items
     */
    public function list(array $items): string
    {
        return Markdown::list($items);
    }

    /** A richtext value (a resolved prop's HTML, or a stored Lexical state) as markdown. */
    public function richtext(mixed $value): string
    {
        return $this->richtext->render($value);
    }

    /** `[title](url): description` for another page, linking its `.md` while markdown is on; null without a slug in this locale. */
    public function pageItem(Page $page): ?string
    {
        return $this->pages()->item($page, $this->locale);
    }

    /**
     * This page's live, indexable children with a slug in the locale, by
     * sort order and then newest first.
     *
     * @return list<Page>
     */
    public function children(int $limit = LlmsTxtSettings::DEFAULT_PER_TYPE): array
    {
        return $this->pages()->children($this->page, $this->locale, $limit);
    }

    private function pages(): MarkdownPages
    {
        return $this->pages ?? app(MarkdownPages::class);
    }
}
