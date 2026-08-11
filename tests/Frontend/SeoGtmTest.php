<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Frontend\Seo\GtmContainerId;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

// Plain GETs render through the @vite-free root view — see
// workbench/resources/views/server-head-test.blade.php's docblock.
beforeEach(function (): void {
    config(['cache.default' => 'array', 'tbtop-cms.render.root_view' => 'server-head-test']);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

it('injects the loader script right after <head> and the noscript right after <body>', function (): void {
    app(Settings::class)->set('seo.gtm_container_id', 'GTM-ABC1234');
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)
        ->toMatch('/<head[^>]*>\n<script>.*googletagmanager\.com\/gtm\.js.*GTM-ABC1234.*<\/script>/s')
        ->toMatch('/<body[^>]*>\n<noscript><iframe src="https:\/\/www\.googletagmanager\.com\/ns\.html\?id=GTM-ABC1234"/');
});

it('injects nothing into Inertia XHR navigation responses', function (): void {
    app(Settings::class)->set('seo.gtm_container_id', 'GTM-ABC1234');
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json');

    expect($this->get('/about', ['X-Inertia' => 'true'])->getContent())
        ->not->toContain('googletagmanager');
});

it('leaves non-HTML responses on the same route group untouched', function (): void {
    app(Settings::class)->set('seo.gtm_container_id', 'GTM-ABC1234');
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    expect($this->get('/robots.txt')->assertOk()->getContent())->not->toContain('googletagmanager')
        ->and($this->get('/sitemap.xml')->assertOk()->getContent())->not->toContain('googletagmanager');
});

it('does not rewrite page content that merely contains head-like markup', function (): void {
    app(Settings::class)->set('seo.gtm_container_id', 'GTM-ABC1234');
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        // A page whose own body text talks about HTML must not gain a second
        // injected snippet: injection is capped at one replacement each.
        'summary' => ['en' => '<head> and <body> explained'],
    ]);

    $html = $this->get('/about')->assertOk()->getContent();

    expect(substr_count((string) $html, 'gtm.js?id='))->toBe(1)
        ->and(substr_count((string) $html, 'ns.html?id='))->toBe(1);
});

it('extracts the id from a stored full install snippet and drops malformed values', function (): void {
    app(Settings::class)->set('seo.gtm_container_id', "<!-- Google Tag Manager -->\n<script>j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;...('GTM-XYZ987');</script>");
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    expect($this->get($page->pathFor('en'))->assertOk()->getContent())
        ->toContain("'GTM-XYZ987'");

    app(Settings::class)->set('seo.gtm_container_id', 'not-a-container-id');

    expect($this->get($page->pathFor('en'))->assertOk()->getContent())
        ->not->toContain('googletagmanager');
});

it('extracts and validates container ids', function (): void {
    expect(GtmContainerId::extract('GTM-ABC1234'))->toBe('GTM-ABC1234')
        ->and(GtmContainerId::extract('gtm-abc1234'))->toBe('GTM-ABC1234')
        ->and(GtmContainerId::extract("<script>j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;</script>('GTM-N7K2Q9');"))->toBe('GTM-N7K2Q9')
        ->and(GtmContainerId::extract('  plain  '))->toBe('plain')
        ->and(GtmContainerId::extract(''))->toBe('')
        ->and(GtmContainerId::valid('GTM-ABC1234'))->toBeTrue()
        ->and(GtmContainerId::valid('GTM-'))->toBeFalse()
        ->and(GtmContainerId::valid('plain'))->toBeFalse()
        ->and(GtmContainerId::valid(''))->toBeFalse();
});

it('does not mine a GTM-looking substring out of unrecognized surrounding text', function (): void {
    // Neither a bare anchored id nor a recognized install snippet — returned
    // as-is, so valid() rejects it and no GTM markup is ever rendered.
    expect(GtmContainerId::extract('not-a-container-GTM-ABC123'))->toBe('not-a-container-GTM-ABC123')
        ->and(GtmContainerId::valid(GtmContainerId::extract('not-a-container-GTM-ABC123')))->toBeFalse()
        ->and(GtmContainerId::valid(GtmContainerId::extract('GTM-ABC123<script>')))->toBeFalse();
});
