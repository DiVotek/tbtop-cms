<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\Blocks\HeroBlock as PackageHeroBlock;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\Discovery\AlphaBlock;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;

uses(RefreshDatabase::class);

it('registers the concrete Block classes of a discovered folder in path order, skipping traits and abstract classes', function (): void {
    config()->set('tbtop-cms.blocks.types', []);
    config()->set('tbtop-cms.blocks.discover', [
        'Tbtop\\Cms\\Tests\\Fixtures\\Discovery' => __DIR__ . '/../Fixtures/Discovery',
    ]);

    expect(app(BlockRegistry::class)->kinds())->toBe(['Alpha', 'Beta']);
});

it('ignores a discovered folder that does not exist', function (): void {
    config()->set('tbtop-cms.blocks.types', []);
    config()->set('tbtop-cms.blocks.discover', ['App\\Missing' => __DIR__ . '/../Fixtures/Nope']);

    expect(app(BlockRegistry::class)->kinds())->toBe([]);
});

it('serves a page when a block is both listed in types and found by discovery', function (): void {
    config(['cache.default' => 'array']);
    config()->set('tbtop-cms.blocks.types', [AlphaBlock::class]);
    config()->set('tbtop-cms.blocks.discover', [
        'Tbtop\\Cms\\Tests\\Fixtures\\Discovery' => __DIR__ . '/../Fixtures/Discovery',
    ]);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [['id' => 'a-1', 'kind' => 'Alpha', 'data' => []]],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.elements.a-1.type', 'Alpha');

    expect(app(BlockRegistry::class)->kinds())->toBe(['Alpha', 'Beta']);
});

it('still refuses two different classes declaring the same kind, naming both', function (): void {
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class]);
    BlockRegistry::extend(fn (): array => [PackageHeroBlock::class]);

    expect(fn () => app(BlockRegistry::class)->kinds())->toThrow(
        InvalidArgumentException::class,
        'Block [Hero] is already registered by [' . HeroBlock::class . ']; [' . PackageHeroBlock::class . '] declares the same kind.',
    );
});
