<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Stringable;

/**
 * Minimal domain-object stand-in for a source-backed page's ResolveContext::$source
 * — mirrors what a real storefront Product/Variant would be: a plain object,
 * Stringable so PageEditPage's Source card can show it as a label.
 */
final readonly class SourcedThing implements Stringable
{
    public function __construct(public string $id) {}

    public function __toString(): string
    {
        return "Thing #{$this->id}";
    }
}
