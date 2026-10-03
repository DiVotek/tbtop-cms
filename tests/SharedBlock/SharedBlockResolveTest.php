<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Fields\BlockContent;
use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Block\BlockResolutionException;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);
    app()->setLocale('uk');
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class]);
});

it('resolves a reference with the shared block content, flattened to the locale like page content', function (): void {
    $shared = SharedBlock::query()->create(['name' => 'CTA', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Hello', 'uk' => 'Pryvit']]]);

    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Hero', 'active' => true, 'shared' => $shared->getKey(), 'data' => ['title' => ['uk' => 'ignored']]],
    ]);

    expect($out)->toHaveCount(1)
        ->and($out[0]->props['title'])->toBe('Pryvit')
        ->and($out[0]->error)->toBeNull();
});

it('marks a reference to a deleted shared block instead of dropping it', function (): void {
    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Hero', 'active' => true, 'shared' => 999],
    ]);

    expect($out[0]->props)->toBe([])
        ->and($out[0]->error)->toBe('Block [Hero] points at shared block #999, which does not exist.');
});

it('treats a shared block of another kind as missing: the page row decides the component', function (): void {
    $shared = SharedBlock::query()->create(['name' => 'X', 'kind' => 'Other', 'data' => []]);

    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Hero', 'active' => true, 'shared' => $shared->getKey()],
    ]);

    expect($out[0]->error)->toContain('does not exist');
});

it('throws for a missing shared block in strict mode', function (): void {
    config()->set('tbtop-cms.blocks.strict', true);

    app(BlockContentResolver::class)->resolve([['id' => 'a', 'kind' => 'Hero', 'active' => true, 'shared' => 999]]);
})->throws(BlockResolutionException::class);

it('keeps the pointer on the envelope and out of data through a save round-trip', function (): void {
    $envelope = BlockContent::toEnvelope([
        ['id' => 'a', 'kind' => 'Hero', 'active' => true, 'shared' => '7', 'title' => 'stray'],
        ['id' => 'b', 'kind' => 'Hero', 'active' => true, 'title' => 'own'],
    ]);

    expect($envelope[0])->toBe(['id' => 'a', 'kind' => 'Hero', 'active' => true, 'shared' => 7, 'data' => []])
        ->and($envelope[1]['data'])->toBe(['title' => 'own'])
        ->and($envelope[1])->not->toHaveKey('shared');

    $rows = BlockContent::toRows($envelope);

    expect($rows[0]['shared'])->toBe(7)
        ->and($rows[1])->not->toHaveKey('shared');
});
