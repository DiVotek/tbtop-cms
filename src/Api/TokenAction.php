<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api;

/** What a token may do. `pages:write` stands for every case but Read. */
enum TokenAction: string
{
    case Read = 'read';
    case Create = 'create';
    case Update = 'update';
    case Publish = 'publish';
    case Delete = 'delete';
    case Media = 'media';

    public const string LEGACY_WRITE = 'pages:write';

    public function ability(): string
    {
        return match ($this) {
            self::Read => 'pages:read',
            self::Media => 'media:upload',
            default => 'pages:' . $this->value,
        };
    }

    public function isWrite(): bool
    {
        return $this !== self::Read;
    }

    /** @return list<string> The abilities a route for this action accepts. */
    public function accepted(): array
    {
        return $this->isWrite() ? [$this->ability(), self::LEGACY_WRITE] : [$this->ability()];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $action): string => $action->value, self::cases());
    }
}
