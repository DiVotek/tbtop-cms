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

it('renders the title template substituting title/site_name/sep placeholders', function (): void {
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set('seo.title_template', '{title} {sep} {site_name}');
    app(Settings::class)->set('seo.title_separator', '|');
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About'], 'title' => ['en' => '']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About | Acme Co');
});

it('defaults the separator to an em dash when seo.title_separator is unset', function (): void {
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set('seo.title_template', '{title} {sep} {site_name}');
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About'], 'title' => ['en' => '']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About — Acme Co');
});

it('strips a dangling separator left behind by an empty site_name substitution', function (): void {
    app(Settings::class)->set('seo.title_template', '{title} {sep} {site_name}');
    app(Settings::class)->set('seo.title_separator', '—');
    // site.name left unset (empty) on purpose.
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About'], 'title' => ['en' => '']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About');
});

it('keeps legacy suffix behavior byte-identical when seo.title_template is empty', function (): void {
    app(Settings::class)->set('seo.title_suffix', '— Acme');
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About Page — Acme');
});

it('applies a different title template per locale', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    app(Settings::class)->set('seo.title_template', ['en' => '{title} | EN', 'uk' => '{title} | UK']);
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        'title' => ['en' => '', 'uk' => ''],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About | EN');

    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'Pro nas | UK');
});

it('falls back to the legacy suffix for a locale whose template entry is empty in a partial locale map', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    app(Settings::class)->set('seo.title_suffix', '— Acme');
    app(Settings::class)->set('seo.title_template', ['en' => '{title} | EN', 'uk' => '']);
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        'title' => ['en' => '', 'uk' => ''],
    ]);

    $this->get('/uk/' . $page->pathFor('uk'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'Pro nas — Acme');
});
