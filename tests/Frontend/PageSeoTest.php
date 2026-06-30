<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Language::factory()->default()->create(['locale' => 'en']);

    $settings = app(Settings::class);
    $settings->set('seo.title_suffix', '— Acme');
    $settings->set('seo.default_description', 'Global description');
    $settings->set('seo.default_og_image', 'global.jpg');

    app(Cms::class)->frontendRoutes();
});

it('lets page SEO overrides win over global defaults', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'is_indexable' => true,
        'seo' => ['description' => 'Page description', 'og_image' => 'page.jpg'],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About Page — Acme')
        ->assertJsonPath('props.seo.description', 'Page description')
        ->assertJsonPath('props.seo.ogImage', 'page.jpg')
        ->assertJsonPath('props.seo.noindex', false);
});

it('falls back to global SEO defaults when page fields are empty', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'is_indexable' => false,
        'seo' => [],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About Page — Acme')
        ->assertJsonPath('props.seo.description', 'Global description')
        ->assertJsonPath('props.seo.ogImage', 'global.jpg')
        ->assertJsonPath('props.seo.canonical', url('/about'))
        ->assertJsonPath('props.seo.noindex', true);
});

it('falls back to the page summary for the meta description', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'summary' => ['en' => 'A short page summary.'],
        'seo' => [],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.description', 'A short page summary.');
});
