<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\BrokenBlock;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);
    app()->setLocale('en');
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class, BrokenBlock::class]);
});

it('resolves a block with locale-flattened props', function (): void {
    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'Hello', 'uk' => 'Pryvit']]],
    ]);

    expect($out)->toHaveCount(1)
        ->and($out[0]->id)->toBe('a')
        ->and($out[0]->kind)->toBe('Hero')
        ->and($out[0]->props)->toBe(['title' => 'Hello']);
});

it('does not discard a non-translatable field coincidentally shaped like a locale map', function (): void {
    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Hero', 'active' => true, 'data' => [
            'title' => ['en' => 'Hello', 'uk' => 'Pryvit'],
            'image' => ['en' => 'not-a-translation', 'url' => '/about'],
        ]],
    ]);

    expect($out[0]->props)->toBe([
        'title' => 'Hello',
        'image' => ['en' => 'not-a-translation', 'url' => '/about'],
    ]);
});

it('falls back to the default locale for a missing translation', function (): void {
    app()->setLocale('uk');

    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'Hello']]],
    ]);

    expect($out[0]->props)->toBe(['title' => 'Hello']);
});

it('skips an inactive block', function (): void {
    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Hero', 'active' => false, 'data' => ['title' => ['en' => 'Hello']]],
    ]);

    expect($out)->toBeEmpty();
});

it('skips an unknown kind without throwing', function (): void {
    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Ghost', 'active' => true, 'data' => []],
    ]);

    expect($out)->toBeEmpty();
});

it('isolates a failing block and still resolves its siblings', function (): void {
    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'x', 'kind' => 'Broken', 'active' => true, 'data' => []],
        ['id' => 'y', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'Hello']]],
    ]);

    expect($out)->toHaveCount(1)
        ->and($out[0]->id)->toBe('y');
});

it('preserves block order', function (): void {
    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'A']]],
        ['id' => 'b', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'B']]],
    ]);

    expect(array_map(fn (object $b): string => $b->id, $out))->toBe(['a', 'b']);
});
