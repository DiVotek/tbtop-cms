<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Pages\Support\ProblemBlocks;
use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Block\ResolvedBlock;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;

uses(RefreshDatabase::class);

// The site must never drop a row the editor is not shown, nor list one the site renders.
it('lists in the editor exactly the rows the resolver skips', function (): void {
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class, HeroBlock::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
    $simple = SharedBlock::query()->create(['name' => 'Promo', 'kind' => 'Simple', 'data' => ['heading' => 'Shared']]);
    $hero = SharedBlock::query()->create(['name' => 'Banner', 'kind' => 'Hero', 'data' => []]);

    $rows = [
        ['id' => 'inline', 'kind' => 'Simple', 'data' => ['heading' => 'Hi']],
        ['id' => 'shared', 'kind' => 'Simple', 'shared' => $simple->id, 'data' => []],
        ['id' => 'shared-string-ref', 'kind' => 'Simple', 'shared' => (string) $simple->id, 'data' => []],
        ['id' => 'ghost', 'kind' => 'Ghost', 'data' => []],
        ['id' => 'ghost-shared', 'kind' => 'Ghost', 'shared' => $simple->id, 'data' => []],
        ['id' => 'missing', 'kind' => 'Simple', 'shared' => 999999, 'data' => []],
        ['id' => 'mismatch', 'kind' => 'Simple', 'shared' => $hero->id, 'data' => []],
    ];

    $rendered = array_map(fn (ResolvedBlock $block): string => $block->id, app(BlockContentResolver::class)->resolve($rows));
    $listed = array_column(ProblemBlocks::of($rows), 'reason', 'id');

    expect($rendered)->toBe(['inline', 'shared', 'shared-string-ref'])
        ->and(array_map(fn ($reason): string => $reason->value, $listed))->toBe([
            'ghost' => 'unregistered',
            'ghost-shared' => 'unregistered',
            'missing' => 'shared_missing',
            'mismatch' => 'shared_kind_mismatch',
        ]);
});
