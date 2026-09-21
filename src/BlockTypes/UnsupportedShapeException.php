<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

use RuntimeException;

/**
 * A PHPDoc construct with no zod mapping. `$blockKind` starts empty — the
 * converter has no notion of which block it's running for — and is filled
 * in once, by BlockShapeExtractor, as the exception propagates out of one
 * block's conversion.
 */
final class UnsupportedShapeException extends RuntimeException
{
    public function __construct(
        public readonly string $keyPath,
        public readonly string $construct,
        public readonly string $blockKind = '',
    ) {
        parent::__construct($this->format());
    }

    public function withBlockKind(string $blockKind): self
    {
        return new self($this->keyPath, $this->construct, $blockKind);
    }

    private function format(): string
    {
        $prefix = $this->blockKind === '' ? '' : "Block [{$this->blockKind}]: ";

        return "{$prefix}unsupported PHPDoc construct at [{$this->keyPath}]: {$this->construct}";
    }
}
