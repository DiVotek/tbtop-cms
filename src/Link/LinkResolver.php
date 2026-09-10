<?php

declare(strict_types=1);

namespace Tbtop\Cms\Link;

use Tbtop\Admin\I18n\TranslatableValue;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;

/**
 * Turns a stored `link` value into the href a block hands its React component.
 * Anything malformed, unpublished or unreachable resolves to null — a block
 * renders no link rather than a broken one.
 */
class LinkResolver
{
    public function __construct(
        private readonly Cms $cms,
        private readonly Languages $languages,
    ) {}

    /** @return array{href: string, type: string, label: string}|null */
    public function resolve(mixed $value, string $locale): ?array
    {
        $link = LinkValue::parse($value);

        if ($link === null) {
            return null;
        }

        $page = $link->type === LinkType::Page ? $this->livePage($link, $locale) : null;

        $href = match ($link->type) {
            LinkType::Page => $page === null ? null : $this->pageHref($link, $page, $locale),
            LinkType::External => $link->value,
            LinkType::Email => $link->value === null ? null : 'mailto:' . $link->value,
            LinkType::Phone => $link->value === null ? null : 'tel:' . $link->telNumber(),
        };

        if ($href === null) {
            return null;
        }

        return [
            'href' => $href,
            'type' => $link->type->value,
            'label' => $this->label($link, $page, $locale),
        ];
    }

    /** Authored text wins; otherwise the page's own name, or the target the author typed. */
    private function label(LinkValue $link, ?Page $page, string $locale): string
    {
        $authored = $link->label($locale, $this->languages->defaultLocale());

        if ($authored !== null) {
            return $authored;
        }

        if ($page !== null) {
            $name = self::pickName($page, $locale);

            if ($name !== null) {
                return $name;
            }
        }

        return $link->value ?? '';
    }

    /** Rendered locale first, then the package-wide default/first-non-empty pick. */
    private static function pickName(Page $page, string $locale): ?string
    {
        $raw = $page->getRawOriginal('name') ?? $page->name;
        $map = TranslatableValue::asLocaleMap($raw);
        $exact = is_array($map) ? ($map[$locale] ?? null) : null;

        if (is_string($exact) && trim($exact) !== '') {
            return $exact;
        }

        $picked = TranslatableValue::pick($raw);

        return is_string($picked) && trim($picked) !== '' ? $picked : null;
    }

    private function livePage(LinkValue $link, string $locale): ?Page
    {
        if ($link->page === null) {
            return null;
        }

        $page = Page::query()->live()->find($link->page);

        // Without a slug for this locale Cms::url() builds a bare "/" — the home page.
        return $page === null || $page->pathFor($locale) === null ? null : $page;
    }

    private function pageHref(LinkValue $link, Page $page, string $locale): string
    {
        $href = $this->cms->url($page, $locale);

        return $link->anchor === null ? $href : $href . '#' . $link->anchor;
    }
}
