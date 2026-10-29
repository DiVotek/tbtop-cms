<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Markdown;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\AcceptHeader;

/**
 * How a request asks for a page's markdown version: a `.md` suffix on the
 * page path (`/about.md`; `/index.md` is the home page), or an `Accept`
 * header that ranks `text/markdown` above `text/html`. Inertia visits are
 * never markdown.
 */
final class MarkdownRequest
{
    public const string CONTENT_TYPE = 'text/markdown; charset=utf-8';

    /** The page path without its `.md` suffix, or null when the path has none. */
    public static function suffixPath(string $path): ?string
    {
        $path = trim($path, '/');

        if (! str_ends_with($path, '.md') || $path === '.md') {
            return null;
        }

        $path = substr($path, 0, -3);

        return $path === 'index' ? '' : $path;
    }

    public static function accepts(Request $request): bool
    {
        if ($request->headers->has('X-Inertia')) {
            return false;
        }

        $markdown = null;
        $html = null;

        // A browser's `*/*` counts for HTML only: it is not a request for markdown.
        foreach (AcceptHeader::fromString($request->headers->get('Accept'))->all() as $item) {
            $value = strtolower($item->getValue());

            if ($value === 'text/markdown') {
                $markdown ??= $item;
            } elseif (in_array($value, ['text/html', 'text/*', '*/*'], true) && ($html === null || $item->getQuality() > $html->getQuality())) {
                $html = $item;
            }
        }

        if ($markdown === null || $markdown->getQuality() <= 0) {
            return false;
        }

        // On equal quality the type listed first wins, as agents send `text/markdown, text/html`.
        return $html === null
            || $markdown->getQuality() > $html->getQuality()
            || ($markdown->getQuality() === $html->getQuality() && $markdown->getIndex() < $html->getIndex());
    }
}
