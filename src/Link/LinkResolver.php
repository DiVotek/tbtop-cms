<?php

declare(strict_types=1);

namespace Tbtop\Cms\Link;

use Tbtop\Cms\Cms;
use Tbtop\Cms\Page\Models\Page;

/**
 * Turns a stored `link` value into the href a block hands its React component.
 * Anything malformed, unpublished or unreachable resolves to null — a block
 * renders no link rather than a broken one.
 */
class LinkResolver
{
    public function __construct(private readonly Cms $cms) {}

    /** @return array{href: string, type: string}|null */
    public function resolve(mixed $value, string $locale): ?array
    {
        $link = LinkValue::parse($value);

        if ($link === null) {
            return null;
        }

        $href = match ($link->type) {
            LinkType::Page => $this->pageHref($link, $locale),
            LinkType::External => $link->value,
            LinkType::Email => $link->value === null ? null : 'mailto:' . $link->value,
            LinkType::Phone => $link->value === null ? null : 'tel:' . $link->telNumber(),
        };

        return $href === null ? null : ['href' => $href, 'type' => $link->type->value];
    }

    private function pageHref(LinkValue $link, string $locale): ?string
    {
        if ($link->page === null) {
            return null;
        }

        $page = Page::query()->live()->find($link->page);

        // Without a slug for this locale Cms::url() builds a bare "/" — the home page.
        if ($page === null || $page->pathFor($locale) === null) {
            return null;
        }

        $href = $this->cms->url($page, $locale);

        return $link->anchor === null ? $href : $href . '#' . $link->anchor;
    }
}
