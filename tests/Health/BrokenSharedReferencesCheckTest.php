<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Layout\Slots;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;

uses(RefreshDatabase::class);

/** @param list<array<string, mixed>> $content */
function brokenRefPage(array $content): Page
{
    return Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About'], 'content' => $content]);
}

it('lists through cms:doctor how many rows of each page and slot point at a missing or retargeted shared block', function (): void {
    $simple = SharedBlock::query()->create(['name' => 'Promo', 'kind' => 'Simple', 'data' => []]);
    $hero = SharedBlock::query()->create(['name' => 'Banner', 'kind' => 'Hero', 'data' => []]);
    $broken = brokenRefPage([
        ['id' => 'a', 'kind' => 'Simple', 'shared' => 999999, 'data' => []],
        ['id' => 'b', 'kind' => 'Simple', 'shared' => $hero->id, 'data' => []],
        ['id' => 'c', 'kind' => 'Simple', 'shared' => $simple->id, 'data' => []],
        ['id' => 'd', 'kind' => 'Simple', 'data' => []],
    ]);
    brokenRefPage([['id' => 'e', 'kind' => 'Simple', 'shared' => $simple->id, 'data' => []]]);
    $trashed = brokenRefPage([['id' => 'f', 'kind' => 'Hero', 'shared' => 999998, 'data' => []]]);
    $trashed->delete();
    Layout::query()->create(['slot' => Slots::FOOTER, 'content' => [['id' => 'g', 'kind' => 'Simple', 'shared' => '999997', 'data' => []]]]);

    $this->artisan('cms:doctor')
        ->expectsOutputToContain("page #{$broken->id}: 2; page #{$trashed->id}: 1; layout " . Slots::FOOTER . ': 1.');
});

it('passes when every reference resolves', function (): void {
    $simple = SharedBlock::query()->create(['name' => 'Promo', 'kind' => 'Simple', 'data' => []]);
    brokenRefPage([['id' => 'a', 'kind' => 'Simple', 'shared' => $simple->id, 'data' => []]]);

    $this->artisan('cms:doctor')
        ->expectsOutputToContain(__('tbtop-cms::health.checks.broken_shared_references.ok'));
});
