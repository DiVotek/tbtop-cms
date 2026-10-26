<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Fields;

use Closure;
use Dom\HTMLDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Tbtop\Cms\Richtext\RichtextLinkRenderer;

/**
 * A richtext value sent to the API: HTML limited to the tags the body is
 * written in, with no attribute but a link's `href`. Anything else is refused
 * rather than dropped, so the caller learns its markup would not survive
 * RichtextHtmlToNodes, and a stored link never carries a `javascript:` URL.
 */
final class RestrictedHtml implements ValidationRule
{
    public const array TAGS = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'em', 'a', 'blockquote'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $document = HTMLDocument::createFromString($value, LIBXML_NOERROR, 'UTF-8');

        // A leading <title>, <meta>, <style>… is parsed into <head>, out of the body's sight.
        if ($document->head !== null && $document->head->childNodes->length > 0) {
            $fail(__('tbtop-cms::api.validation.html_head'));

            return;
        }

        $body = $document->body;
        if ($body === null) {
            return;
        }

        $tags = [];
        $attributes = [];
        $badLinks = [];
        foreach ($body->getElementsByTagName('*') as $element) {
            $tag = strtolower($element->localName);
            if (! in_array($tag, self::TAGS, true)) {
                $tags[$tag] = true;
            }
            foreach ($element->attributes as $attr) {
                if ($tag !== 'a' || strtolower($attr->name) !== 'href') {
                    $attributes["{$tag}[{$attr->name}]"] = true;
                }
            }
            if ($tag === 'a' && ! self::isAllowedHref((string) $element->getAttribute('href'))) {
                $badLinks[(string) $element->getAttribute('href')] = true;
            }
        }

        if ($tags !== []) {
            $fail(__('tbtop-cms::api.validation.html_tags', ['tags' => implode(', ', array_keys($tags)), 'allowed' => implode(', ', self::TAGS)]));
        }
        if ($attributes !== []) {
            $fail(__('tbtop-cms::api.validation.html_attributes', ['attributes' => implode(', ', array_keys($attributes))]));
        }
        if ($badLinks !== []) {
            $fail(__('tbtop-cms::api.validation.html_links', ['links' => implode(', ', array_keys($badLinks))]));
        }
    }

    /**
     * The renderer's own scheme check (http, https, mailto, tel, relative), plus
     * no protocol-relative URL: `//host` and `/\host` leave the site, and the
     * renderer would let them through as relative.
     */
    private static function isAllowedHref(string $href): bool
    {
        $normalized = trim(str_replace(["\t", "\r", "\n"], '', $href), "\x00..\x20");
        if (str_starts_with($normalized, '//') || str_starts_with($normalized, '/\\') || str_starts_with($normalized, '\\')) {
            return false;
        }

        return app(RichtextLinkRenderer::class)->isAllowedUrl($href);
    }
}
