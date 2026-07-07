<?php

declare(strict_types=1);

namespace Tbtop\Cms\Layout;

use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Block\ResolvedBlock;
use Tbtop\Cms\Layout\Models\Layout;

class LayoutResolver
{
    public function __construct(
        private readonly BlockContentResolver $blockContentResolver,
    ) {}

    /** @return array<string, list<ResolvedBlock>> */
    public function resolve(): array
    {
        $slots = [];

        foreach (Slots::all() as $slot) {
            $slots[$slot] = $this->resolveSlot($slot);
        }

        return $slots;
    }

    /** @return list<ResolvedBlock> */
    private function resolveSlot(string $slot): array
    {
        $layout = Layout::query()->where('slot', $slot)->first();

        if (! $layout instanceof Layout || $layout->content === null) {
            return [];
        }

        return $this->blockContentResolver->resolve($layout->content);
    }
}
