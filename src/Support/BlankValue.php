<?php

declare(strict_types=1);

namespace Tbtop\Cms\Support;

/**
 * The one rule for "the editor saved nothing here": null, '', whitespace, an
 * empty array, or a richtext document holding only empty paragraphs. The
 * editor stores '' (or Lexical's empty document) for every locale it showed,
 * so a translation lookup treats these as missing rather than as content.
 *
 * @internal
 */
final class BlankValue
{
    public static function is(mixed $value): bool
    {
        if (is_array($value)) {
            // A richtext field saves the editor's empty document, not null.
            return $value === [] || self::isBlankDocument($value);
        }

        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * Lexical keeps one empty paragraph once its text is erased. Blank means
     * paragraphs holding only whitespace text and line breaks: any other node
     * (an image, a list, a heading) is content even with no text.
     *
     * @param  array<array-key, mixed>  $document
     */
    public static function isBlankDocument(array $document): bool
    {
        $children = is_array($document['root'] ?? null) ? ($document['root']['children'] ?? null) : null;
        if (! is_array($children)) {
            return false;
        }

        foreach ($children as $paragraph) {
            if (! is_array($paragraph) || ($paragraph['type'] ?? null) !== 'paragraph') {
                return false;
            }
            foreach (is_array($paragraph['children'] ?? null) ? $paragraph['children'] : [] as $inline) {
                if (! self::isBlankInline($inline)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function isBlankInline(mixed $inline): bool
    {
        if (! is_array($inline)) {
            return false;
        }
        if (($inline['type'] ?? null) === 'linebreak') {
            return true;
        }

        return ($inline['type'] ?? null) === 'text'
            && preg_match('/^[\s\x{00A0}]*$/u', is_scalar($inline['text'] ?? null) ? (string) $inline['text'] : '') === 1;
    }
}
