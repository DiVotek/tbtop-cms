<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use RuntimeException;
use Throwable;

class BlockResolutionException extends RuntimeException
{
    public function __construct(
        public readonly string $kind,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Block [{$kind}] failed to resolve: {$reason}", 0, $previous);
    }

    public static function unknownKind(string $kind): self
    {
        return new self($kind, 'unknown kind');
    }

    public static function resolveFailed(string $kind, Throwable $previous): self
    {
        return new self($kind, $previous->getMessage(), $previous);
    }
}
