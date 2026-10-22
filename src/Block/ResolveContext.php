<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Tbtop\Cms\Link\LinkResolver;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\PageTranslation;
use Tbtop\Cms\Richtext\RichtextHtmlRenderer;

final readonly class ResolveContext
{
    public function __construct(
        public string $locale,
        public string $fallbackLocale,
        public ?Page $page = null,
        private ?MediaUrlResolver $mediaUrlResolver = null,
        private ?RichtextHtmlRenderer $richtextHtmlRenderer = null,
        public ?object $source = null,
        private ?LinkResolver $linkResolver = null,
        /**
         * The page's resolved type fields (PageTypeInterface::fields(), base
         * type then subtype), filled before the blocks resolve so any of
         * them can read a type field such as an address or a deadline.
         *
         * @var array<string, mixed>
         */
        public array $fields = [],
    ) {}

    public function field(string $name): mixed
    {
        return $this->fields[$name] ?? null;
    }

    public function mediaUrl(string | int $id): ?string
    {
        $resolver = $this->mediaUrlResolver ?? app(MediaUrlResolver::class);

        return $resolver->resolve($id);
    }

    /**
     * The media as an `<img>`: `src`, a `srcset` of its proportional variants
     * (null when there are none), `width`, `height` and `alt`. Null for a
     * missing or pathless row, like mediaUrl().
     *
     * @return array{src: string, srcset: string|null, width: int|null, height: int|null, alt: string|null}|null
     */
    public function mediaImage(string | int $id): ?array
    {
        $resolver = $this->mediaUrlResolver ?? app(MediaUrlResolver::class);

        return $resolver->image($id);
    }

    public function richtextHtml(mixed $value): string
    {
        $renderer = $this->richtextHtmlRenderer ?? app(RichtextHtmlRenderer::class);

        return $renderer->render($value);
    }

    /**
     * A translatable text field of the page being rendered — name, title,
     * heading or summary — in the active locale, falling back to the default
     * locale; null when neither is filled or there is no page.
     */
    public function pageText(string $field): ?string
    {
        if ($this->page === null) {
            return null;
        }
        $value = PageTranslation::get($this->page, $field, $this->locale, $this->fallbackLocale);

        return is_scalar($value) ? (string) $value : null;
    }

    /** The page's Body as HTML, with the same locale fallback; '' when empty or there is no page. */
    public function pageBodyHtml(): string
    {
        if ($this->page === null) {
            return '';
        }

        return $this->richtextHtml(PageTranslation::get($this->page, 'body', $this->locale, $this->fallbackLocale));
    }

    /** @return array{href: string, type: string, label: string}|null Null for a malformed, unpublished or locale-slugless target. */
    public function link(mixed $value): ?array
    {
        $resolver = $this->linkResolver ?? app(LinkResolver::class);

        return $resolver->resolve($value, $this->locale);
    }
}
