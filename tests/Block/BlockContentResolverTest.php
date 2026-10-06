<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tbtop\Cms\Block\BlockContentResolver;
use Tbtop\Cms\Block\BlockResolutionException;
use Tbtop\Cms\Block\Support\LocaleFlattener;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\BrokenBlock;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;
use Tbtop\Cms\Tests\Fixtures\LinkBlock;
use Tbtop\Cms\Tests\Fixtures\PageTextBlock;
use Tbtop\Cms\Tests\Fixtures\SourceAwareBlock;
use Tbtop\Cms\Tests\Fixtures\SourcedThing;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);
    app()->setLocale('en');
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class, BrokenBlock::class, SourceAwareBlock::class, LinkBlock::class, PageTextBlock::class]);
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
    // Checked at the flattener: the form-driven resolve() keeps only form fields, so the block output cannot show it.
    $flat = LocaleFlattener::flattenData(
        ['title' => ['en' => 'Hello', 'uk' => 'Pryvit'], 'image' => ['en' => 'not-a-translation', 'url' => '/about']],
        'en',
        'en',
        ['en', 'uk'],
        ['title'],
    );

    expect($flat)->toBe([
        'title' => 'Hello',
        'image' => ['en' => 'not-a-translation', 'url' => '/about'],
    ]);
});

it('drops input that has no field in the form', function (): void {
    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'Hello'], 'stray' => 'x']],
    ]);

    expect($out[0]->props)->toBe(['title' => 'Hello']);
});

it('leaves a link field value untouched through the flattener', function (): void {
    $page = Page::factory()->create(['name' => ['en' => 'Target'], 'status' => PageStatus::Published]);

    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'LinkBlock', 'active' => true, 'data' => [
            'cta' => ['type' => 'page', 'page' => (string) $page->id, 'value' => null, 'anchor' => 'how'],
        ]],
    ]);

    expect($out[0]->props['cta'])
        ->toBe(['href' => '/' . $page->pathFor('en') . '#how', 'type' => 'page', 'label' => 'Target']);
});

it("resolves a link's label map through the flattener to the active locale", function (): void {
    $page = Page::factory()->create(['name' => ['en' => 'Target'], 'status' => PageStatus::Published]);

    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'LinkBlock', 'active' => true, 'data' => [
            'cta' => [
                'type' => 'page',
                'page' => (string) $page->id,
                'value' => null,
                'anchor' => null,
                'label' => ['en' => 'Buy now', 'uk' => 'Купити'],
            ],
        ]],
    ]);

    expect($out[0]->props['cta']['label'])->toBe('Buy now');
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

it('drops an unknown kind without throwing', function (): void {
    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Ghost', 'active' => true, 'data' => []],
        ['id' => 'b', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'Hello']]],
    ]);

    expect(array_map(fn (object $b): string => $b->id, $out))->toBe(['b']);
});

it('drops a block whose resolve() throws, logs it, and still resolves its siblings', function (): void {
    Log::spy();

    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'x', 'kind' => 'Broken', 'active' => true, 'data' => []],
        ['id' => 'y', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'Hello']]],
    ]);

    expect(array_map(fn (object $b): string => $b->id, $out))->toBe(['y']);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'block.resolve_failed' && $context['id'] === 'x');
});

it('preserves block order', function (): void {
    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'A']]],
        ['id' => 'b', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'B']]],
    ]);

    expect(array_map(fn (object $b): string => $b->id, $out))->toBe(['a', 'b']);
});

it('orders resolved blocks by the envelope sort field, not array position', function (): void {
    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Hero', 'active' => true, 'sort' => 2, 'data' => ['title' => ['en' => 'A']]],
        ['id' => 'b', 'kind' => 'Hero', 'active' => true, 'sort' => 1, 'data' => ['title' => ['en' => 'B']]],
    ]);

    expect(array_map(fn (object $b): string => $b->id, $out))->toBe(['b', 'a']);
});

it('falls back to array index for legacy blocks with no sort field', function (): void {
    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'A']]],
        ['id' => 'b', 'kind' => 'Hero', 'active' => true, 'sort' => 0, 'data' => ['title' => ['en' => 'B']]],
    ]);

    // 'a' has no sort (falls back to its index, 0); 'b' explicitly claims
    // sort 0 too — the stable sort keeps 'a' first since it appeared first.
    expect(array_map(fn (object $b): string => $b->id, $out))->toBe(['a', 'b']);
});

it('throws on an unknown kind when strict mode is enabled', function (): void {
    config()->set('tbtop-cms.blocks.strict', true);

    app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'Ghost', 'active' => true, 'data' => []],
    ]);
})->throws(BlockResolutionException::class);

it('exposes the resolve() $source argument on the ResolveContext passed to each block', function (): void {
    $source = new SourcedThing('thing-1');

    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'a', 'kind' => 'SourceAware', 'active' => true, 'data' => []],
    ], source: $source);

    expect($out[0]->props)->toBe(['source' => 'Thing #thing-1']);
});

it('throws with the original error as previous when strict mode is enabled', function (): void {
    config()->set('tbtop-cms.blocks.strict', true);

    $caught = null;

    try {
        app(BlockContentResolver::class)->resolve([
            ['id' => 'x', 'kind' => 'Broken', 'active' => true, 'data' => []],
        ]);
    } catch (BlockResolutionException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(BlockResolutionException::class)
        ->and($caught?->kind)->toBe('Broken')
        ->and($caught?->getPrevious())->toBeInstanceOf(RuntimeException::class)
        ->and($caught?->getPrevious()?->getMessage())->toBe('boom');
});

it('hands a block the page texts with a blank active-locale translation falling back to the default locale', function (): void {
    app()->setLocale('uk');
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        // The editor stores '' and an empty document for a locale left untouched.
        'heading' => ['en' => 'English heading', 'uk' => ''],
        'body' => [
            'en' => ['root' => ['type' => 'root', 'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'English body', 'format' => 0]]]]]],
            'uk' => ['root' => ['type' => 'root', 'children' => []]],
        ],
    ]);

    $out = app(BlockContentResolver::class)->resolve([
        ['id' => 'pt-1', 'kind' => 'PageText', 'data' => []],
    ], $page);

    expect($out[0]->props)->toBe(['heading' => 'English heading', 'bodyHtml' => '<p>English body</p>']);

    $none = app(BlockContentResolver::class)->resolve([['id' => 'pt-2', 'kind' => 'PageText', 'data' => []]]);

    expect($none[0]->props)->toBe(['heading' => null, 'bodyHtml' => '']);
});
