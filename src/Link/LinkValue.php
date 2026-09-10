<?php

declare(strict_types=1);

namespace Tbtop\Cms\Link;

/**
 * Parsed form of a `link` field value. Editor content is untrusted, so consumers
 * go through parse() rather than reading keys; it normalizes `page` to a string.
 */
final readonly class LinkValue
{
    private function __construct(
        public LinkType $type,
        public ?string $page,
        public ?string $value,
        public ?string $anchor,
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
        );
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
