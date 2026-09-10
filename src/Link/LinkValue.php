<?php

declare(strict_types=1);

namespace Tbtop\Cms\Link;

/**
 * Parsed form of a `link` field value. Editor content is untrusted, so consumers
 * go through parse() rather than reading keys; it normalizes `page` to a string.
 */
final readonly class LinkValue
{
    /** @param array<string, string>|null $labels Empty entries dropped; null once nothing is left. */
    private function __construct(
        public LinkType $type,
        public ?string $page,
        public ?string $value,
        public ?string $anchor,
        public ?array $labels,
    ) {}

    public static function parse(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $type = LinkType::tryFrom(is_string($raw['type'] ?? null) ? $raw['type'] : '');

        if ($type === null) {
            return null;
        }

        return new self(
            $type,
            $type === LinkType::Page ? self::scalar($raw['page'] ?? null) : null,
            $type === LinkType::Page ? null : self::scalar($raw['value'] ?? null),
            $type === LinkType::Page ? self::scalar($raw['anchor'] ?? null) : null,
            self::labels($raw['label'] ?? null),
        );
    }

    /**
     * LocaleFlattener::isLocaleMap() collapses `label` to one string before
     * resolve() runs, so both shapes reach here; '*' holds the collapsed one.
     *
     * @return array<string, string>|null
     */
    private static function labels(mixed $raw): ?array
    {
        if (is_string($raw) || is_int($raw)) {
            $one = self::scalar($raw);

            return $one === null ? null : ['*' => $one];
        }

        if (! is_array($raw)) {
            return null;
        }

        $labels = [];

        foreach ($raw as $locale => $text) {
            $one = self::scalar($text);

            if (is_string($locale) && $one !== null) {
                $labels[$locale] = $one;
            }
        }

        return $labels === [] ? null : $labels;
    }

    /** Rendered locale, then fallback, then any non-empty — matching TranslatableValue::pick(). */
    public function label(string $locale, string $fallback): ?string
    {
        if ($this->labels === null) {
            return null;
        }

        foreach ([$locale, $fallback, '*'] as $key) {
            if (($this->labels[$key] ?? null) !== null) {
                return $this->labels[$key];
            }
        }

        return array_values($this->labels)[0] ?? null;
    }

    /** Digits and a single leading `+` — what `tel:` accepts; the stored value keeps the author's spacing. */
    public function telNumber(): string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $this->value) ?? '';

        return str_starts_with(trim((string) $this->value), '+') ? '+' . $digits : $digits;
    }

    private static function scalar(mixed $raw): ?string
    {
        if (is_int($raw)) {
            return (string) $raw;
        }

        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }
}
