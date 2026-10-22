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
use Tbtop\Cms\Tests\Fixtures\LinkBlock;

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

it('caches layout content per locale and never leaks an incomplete-class object into the payload', function (): void {
    // 'file' really serializes (the 'array' store used elsewhere in this
    // file does not); disallowing object classes on unserialize matches a
    // hardened host's cache config and is what turns a cached ResolvedBlock
    // into __PHP_Incomplete_Class on the next read if one were ever cached.
    config(['cache.default' => 'file', 'cache.serializable_classes' => false]);
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk', 'is_frontend_active' => true]);

    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'h1', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => ['en' => 'Header EN', 'uk' => 'Header UA']]],
        ],
    ]);

    app(Cms::class)->frontendRoutes();

    $en = $this->get('/about', ['X-Inertia' => 'true'])->assertOk();
    $uk = $this->get('/uk/about', ['X-Inertia' => 'true'])->assertOk();

    $en->assertJsonPath('props.cms.layout.header.0.props.title', 'Header EN');
    $uk->assertJsonPath('props.cms.layout.header.0.props.title', 'Header UA');

    expect($en->getContent())->not->toContain('__PHP_Incomplete_Class_Name');
    expect($uk->getContent())->not->toContain('__PHP_Incomplete_Class_Name');
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

it('serves a layout link to a scheduled page once its published_at passes', function (): void {
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class, LinkBlock::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $scheduled = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Launch'],
        'published_at' => now()->addSeconds(10),
    ]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'l1', 'kind' => 'LinkBlock', 'active' => true, 'data' => ['cta' => ['type' => 'page', 'page' => (string) $scheduled->id]]],
        ],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertJsonPath('props.cms.layout.header.0.props.cta', null);

    $this->travel(11)->seconds();

    // A cached layout must not outlive the schedule transition: the link
    // appears without any write happening at that instant.
    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertJsonPath('props.cms.layout.header.0.props.cta.href', '/' . $scheduled->pathFor('en'));
});

it('shares the draft-preview banner text in the request locale', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->get('/' . $page->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.cms.strings.previewBanner', 'Draft preview — this page is not published.');
    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.cms.strings.previewBanner', __('tbtop-cms::pages.preview.banner', [], 'uk'));
});
