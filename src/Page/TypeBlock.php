<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page;

/**
 * One block a page type declares (see PageTypeInterface::blocks()).
 */
final readonly class TypeBlock
{
    private function __construct(
        public string $kind,
        public bool $isGeneral,
    ) {}

    /** A block in the content flow: its own tab, ordered among the page's other blocks. */
    public static function content(string $kind): self
    {
        return new self($kind, false);
    }

    /** A block whose form sits on the General tab and whose props become the page's `fields`. */
    public static function general(string $kind): self
    {
        return new self($kind, true);
    }
}
