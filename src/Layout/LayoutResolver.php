<?php

declare(strict_types=1);

namespace Tbtop\Cms\Layout;

use Illuminate\Support\Facades\Schema;
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
        return array_map(
            fn (array $content): array => $this->blockContentResolver->resolve($content),
            $this->content(),
        );
    }

    /**
     * Stored, unresolved block content of every slot, keyed by slot — an
     * empty list for a slot with no saved layout.
     *
     * @return array<string, array<int, mixed>>
     */
    public function content(): array
    {
        if (! Schema::hasTable((new Layout)->getTable())) {
            return array_fill_keys(Slots::all(), []);
        }

        $slots = [];

        foreach (Slots::all() as $slot) {
            $slots[$slot] = Layout::query()->where('slot', $slot)->first()->content ?? [];
        }

        return $slots;
    }
}
