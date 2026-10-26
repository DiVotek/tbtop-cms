<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Fields;

use Closure;
use Dom\HTMLDocument;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A richtext value sent to the API: HTML limited to the tags the body is
 * written in. Anything else is refused rather than dropped, so the caller
 * learns its markup would not survive RichtextHtmlToNodes.
 */
final class RestrictedHtml implements ValidationRule
{
    public const array TAGS = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'em', 'a', 'blockquote'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $body = HTMLDocument::createFromString($value, LIBXML_NOERROR, 'UTF-8')->body;
        if ($body === null) {
            return;
        }

        $refused = [];
        foreach ($body->getElementsByTagName('*') as $element) {
            if (! in_array(strtolower($element->localName), self::TAGS, true)) {
                $refused[strtolower($element->localName)] = true;
            }
        }

        if ($refused !== []) {
            $fail(__('tbtop-cms::api.validation.html_tags', [
                'tags' => implode(', ', array_keys($refused)),
                'allowed' => implode(', ', self::TAGS),
            ]));
        }
    }
}
