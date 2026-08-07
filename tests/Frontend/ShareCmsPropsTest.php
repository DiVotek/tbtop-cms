<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Pages\Support\LayoutForm;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Layout\Slots;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class]);
    config()->set('cache.default', 'array');
});

it('shares the default locale and per-language is_default flags', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.cms.defaultLocale', 'en')
        ->assertJsonPath('props.cms.languages.0.locale', 'en')
        ->assertJsonPath('props.cms.languages.0.is_default', true)
        ->assertJsonPath('props.cms.languages.1.locale', 'uk')
        ->assertJsonPath('props.cms.languages.1.is_default', false);
});

it('shares resolved layout slots with locale-flattened blocks', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
    ]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'h1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'Header title']]],
        ],
    ]);

    Layout::query()->create([
        'slot' => Slots::FOOTER,
        'content' => [
            ['id' => 'f1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'Footer title']]],
        ],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.cms.layout.header.0.id', 'h1')
        ->assertJsonPath('props.cms.layout.header.0.props.title', 'Header title')
        ->assertJsonPath('props.cms.layout.footer.0.id', 'f1')
        ->assertJsonPath('props.cms.layout.footer.0.props.title', 'Footer title');
});

it('shares empty layout slots when no layout rows exist', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.cms.layout.header', [])
        ->assertJsonPath('props.cms.layout.footer', []);
});

it('serves a fresh layout after a slot is saved via LayoutForm::save()', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertJsonPath('props.cms.layout.header', []);

    LayoutForm::save([
        'content' => [['kind' => 'Hero', 'title' => 'Hi']],
    ], Slots::HEADER);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertJsonPath('props.cms.layout.header.0.props.title', 'Hi');
});

it('skips an inactive block in a layout slot', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
    ]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'h1', 'kind' => 'Hero', 'active' => false, 'data' => ['title' => ['en' => 'Hidden']]],
        ],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.cms.layout.header', []);
});
