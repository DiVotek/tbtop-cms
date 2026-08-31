<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Tests\Fixtures\FaqPageType;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;
use Tbtop\Cms\Tests\Fixtures\SourceAwareBlock;
use Tbtop\Cms\Tests\Fixtures\SourcedType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    config()->set('tbtop-cms.blocks.types', [HeroBlock::class, SourceAwareBlock::class]);
    config()->set('tbtop-cms.pages.types', [SourcedType::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

it('renders the type template when a source-backed page has no content', function (): void {
    Page::factory()->create([
        'type' => 'sourced',
        'source_id' => 'thing-1',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Thing One'],
        'content' => null,
    ]);

    $this->get('/thing-one', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.elements.tpl-hero.type', 'Hero')
        ->assertJsonPath('props.render.elements.tpl-hero.props.title', 'Template Hero');
});

it('renders explicit content instead of the template when the source-backed page has content', function (): void {
    Page::factory()->create([
        'type' => 'sourced',
        'source_id' => 'thing-1',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Thing One'],
        'content' => [
            ['id' => 'custom-hero', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Custom']]],
        ],
    ]);

    $this->get('/thing-one', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.elements.custom-hero.props.title', 'Custom')
        ->assertJsonMissingPath('props.render.elements.tpl-hero');
});

it('404s a source-backed page whose source no longer exists', function (): void {
    Page::factory()->create([
        'type' => 'sourced',
        'source_id' => 'missing',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Gone Thing'],
        'content' => null,
    ]);

    $this->get('/gone-thing', ['X-Inertia' => 'true'])->assertNotFound();
});

it('exposes the source to a block resolved on a source-backed page', function (): void {
    Page::factory()->create([
        'type' => 'sourced',
        'source_id' => 'thing-1',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Thing One'],
        'content' => [
            ['id' => 'src-1', 'kind' => 'SourceAware', 'data' => []],
        ],
    ]);

    $this->get('/thing-one', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.elements.src-1.props.source', 'Thing #thing-1');
});

it('renders a page whose type is no longer registered', function (): void {
    config()->set('tbtop-cms.pages.types', [FaqPageType::class]);
    app()->forgetInstance(PageTypeRegistry::class);

    Page::factory()->create([
        'type' => 'faq',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Faq Page'],
        'content' => [
            ['id' => 'h1', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Still here']]],
        ],
    ]);

    // The host dropped FaqPageType from config, but the rows it created stay:
    // an unresolvable type must not take the page's own content down with it.
    config()->set('tbtop-cms.pages.types', []);
    app()->forgetInstance(PageTypeRegistry::class);

    $this->get('/faq-page', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.elements.h1.props.title', 'Still here');
});
