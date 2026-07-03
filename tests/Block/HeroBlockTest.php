<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Block\Blocks\HeroBlock;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

it('resolves stored data into json-render props', function (): void {
    $props = (new HeroBlock)->resolve([
        'title' => 'Welcome',
        'subtitle' => 'A subtitle',
        'image' => 'https://example.test/i.png',
        'ctaLabel' => 'Go',
        'ctaHref' => 'https://example.test',
    ], new ResolveContext('en', 'en'));

    expect($props)->toBe([
        'title' => 'Welcome',
        'subtitle' => 'A subtitle',
        'image' => 'https://example.test/i.png',
        'cta' => ['label' => 'Go', 'href' => 'https://example.test'],
    ]);
});

it('omits the cta unless both label and link are present', function (): void {
    $props = (new HeroBlock)->resolve(
        ['title' => 'Welcome', 'ctaLabel' => 'Go'],
        new ResolveContext('en', 'en'),
    );

    expect($props['cta'])->toBeNull();
});

it('nulls blank optional fields', function (): void {
    $props = (new HeroBlock)->resolve(
        ['title' => 'Welcome', 'subtitle' => '   ', 'image' => ''],
        new ResolveContext('en', 'en'),
    );

    expect($props['subtitle'])->toBeNull()
        ->and($props['image'])->toBeNull();
});

it('renders localized hero props through the page controller', function (): void {
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [[
            'id' => 'hero-1',
            'kind' => 'Hero',
            'active' => true,
            'data' => [
                'title' => ['en' => 'Welcome'],
                'ctaLabel' => ['en' => 'Go'],
                'ctaHref' => 'https://example.test',
            ],
        ]],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.elements.hero-1.type', 'Hero')
        ->assertJsonPath('props.render.elements.hero-1.props.title', 'Welcome')
        ->assertJsonPath('props.render.elements.hero-1.props.cta.label', 'Go')
        ->assertJsonPath('props.render.elements.hero-1.props.cta.href', 'https://example.test')
        ->assertJsonPath('props.render.elements.hero-1.props.subtitle', null);
});

it('still resolves a title when a sibling locale key belongs to a since-deleted language', function (): void {
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    $fr = Language::factory()->create(['locale' => 'fr']);
    $fr->delete();
    app(Cms::class)->frontendRoutes();

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [[
            'id' => 'hero-1',
            'kind' => 'Hero',
            'active' => true,
            'data' => ['title' => ['en' => 'Welcome', 'fr' => 'Bienvenue']],
        ]],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.elements.hero-1.props.title', 'Welcome');
});
