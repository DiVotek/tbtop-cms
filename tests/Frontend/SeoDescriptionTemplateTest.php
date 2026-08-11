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
    app(Cms::class)->frontendRoutes();
});

it('renders the description template substituting description/site_name/sep placeholders', function (): void {
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set('seo.description_template', '{description} {sep} {site_name}');
    app(Settings::class)->set('seo.title_separator', '|');
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'summary' => ['en' => 'All about us'],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.description', 'All about us | Acme Co');
});

it('keeps the description untouched when seo.description_template is empty', function (): void {
    app(Settings::class)->set('site.name', 'Acme Co');
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'summary' => ['en' => 'All about us'],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.description', 'All about us');
});

it('strips a dangling separator when the description base resolves empty', function (): void {
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set('seo.description_template', '{description} {sep} {site_name}');
    // No seo.description, no summary, no default description.
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.description', 'Acme Co');
});

it('leaves og:description on the untemplated base description', function (): void {
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set('seo.description_template', '{description} {sep} {site_name}');
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'summary' => ['en' => 'All about us'],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.description', 'All about us — Acme Co')
        ->assertJsonPath('props.seo.ogDescription', 'All about us');
});

it('applies a different description template per locale, empty entry meaning as-is', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    app(Settings::class)->set('seo.description_template', ['en' => '{description} | EN', 'uk' => '']);
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        'summary' => ['en' => 'All about us', 'uk' => 'Vse pro nas'],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.description', 'All about us | EN');

    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.description', 'Vse pro nas');
});
