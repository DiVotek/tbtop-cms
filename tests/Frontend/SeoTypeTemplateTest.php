<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Frontend\Seo\SeoTemplates;
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

function publishedArticle(string $name = 'Post', ?string $categoryName = 'Blog'): Page
{
    $parent = $categoryName === null ? null : Page::factory()->create([
        'type' => 'category',
        'status' => PageStatus::Published,
        'name' => ['en' => $categoryName],
        'title' => ['en' => $categoryName],
    ]);

    return Page::factory()->create([
        'type' => 'article',
        'parent_id' => $parent?->getKey(),
        'status' => PageStatus::Published,
        'published_at' => now()->subDay(),
        'name' => ['en' => $name],
        'title' => ['en' => ''],
    ]);
}

it('prefers a page type template over the site-wide one', function (): void {
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set('seo.title_template', '{title} {sep} {site_name}');
    app(Settings::class)->set(SeoTemplates::key('article', 'title'), '{title} {sep} {parent} {sep} {site_name}');

    $article = publishedArticle();

    $this->get('/' . $article->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'Post — Blog — Acme Co');
});

it('leaves other page types on the site-wide template', function (): void {
    // The article template must not leak onto a plain page — the whole point
    // of keying templates by type.
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set('seo.title_template', '{title} {sep} {site_name}');
    app(Settings::class)->set(SeoTemplates::key('article', 'title'), 'ARTICLE {title}');

    Page::factory()->create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => ''],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About — Acme Co');
});

it('falls back to the site-wide template when the type template is blank', function (): void {
    // Blank means "not set for this type", which is what lets an editor clear
    // a type's template rather than being stuck with an empty title.
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set('seo.title_template', '{title} {sep} {site_name}');
    app(Settings::class)->set(SeoTemplates::key('article', 'title'), '');

    $article = publishedArticle();

    $this->get('/' . $article->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'Post — Acme Co');
});

it('drops the stray separator when a page has no parent to name', function (): void {
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set(SeoTemplates::key('page', 'title'), '{title} {sep} {parent} {sep} {site_name}');

    Page::factory()->create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => ''],
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'About — Acme Co');
});

it('substitutes the publication date', function (): void {
    app(Settings::class)->set(SeoTemplates::key('article', 'title'), '{title} {sep} {date}');

    $article = publishedArticle();
    $expected = 'Post — ' . $article->published_at->isoFormat('LL');

    $this->get('/' . $article->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', $expected);
});

it('applies a per-type description template too', function (): void {
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set(SeoTemplates::key('article', 'description'), '{description} {sep} {parent}');

    $parent = Page::factory()->create([
        'type' => 'category',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Blog'],
        'title' => ['en' => 'Blog'],
    ]);
    $article = Page::factory()->create([
        'type' => 'article',
        'parent_id' => $parent->getKey(),
        'status' => PageStatus::Published,
        'name' => ['en' => 'Post'],
        'title' => ['en' => ''],
        'summary' => ['en' => 'A short summary.'],
    ]);

    $this->get('/' . $article->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.description', 'A short summary. — Blog');
});

it('keeps the page own meta title above every template', function (): void {
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set(SeoTemplates::key('article', 'title'), '{title} {sep} {parent}');

    $article = publishedArticle();
    $article->update(['seo' => ['title' => ['en' => 'Hand written']]]);

    // The template still wraps it — a meta title replaces the base, not the
    // template — but the base is the editor's string, not the page name.
    $this->get('/' . $article->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.title', 'Hand written — Blog');
});
