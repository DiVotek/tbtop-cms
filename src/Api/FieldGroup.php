<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api;

/** A set of page fields a token may write. A token with none of them writes every field. */
enum FieldGroup: string
{
    case Seo = 'seo';
    case Heading = 'heading';
    case Text = 'text';
    case Url = 'url';
    case Type = 'type';
    case Media = 'media';

    public const string PREFIX = 'fields:';

    public function ability(): string
    {
        return self::PREFIX . $this->value;
    }

    /** The group a translated text key belongs to, null for a key the API does not know. */
    public static function ofTextKey(string $key): ?self
    {
        return match ($key) {
            'heading' => self::Heading,
            'name', 'summary', 'body' => self::Text,
            'slug' => self::Url,
            default => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $group): string => $group->value, self::cases());
    }
}
