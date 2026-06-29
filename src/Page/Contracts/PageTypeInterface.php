<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Contracts;

interface PageTypeInterface
{
    public function key(): string;

    public function label(): string;

    /** @return list<string> */
    public function allowedChildren(): array;

    public function requiresParent(): bool;

    /** Max nesting depth for container types; leaf types are not depth-checked. */
    public function maxDepth(): int;
}
