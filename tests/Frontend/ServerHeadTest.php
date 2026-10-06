<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

// See workbench/resources/views/server-head-test.blade.php's docblock for why
// a plain GET must render through a @vite-free root view rather than the
// workbench's normal app.blade.php: the checked-in manifest workbench/public/
// is gitignored, so a plain-GET test against the real root view would pass
// locally and throw ViteManifestNotFoundException on a fresh CI checkout.
beforeEach(function (): void {
    config(['cache.default' => 'array', 'tbtop-cms.render.root_view' => 'server-head-test']);
    Storage::fake('public');
    Language::factory()->default()->create(['locale' => 'en']);
});

function serverHeadMedia(string $name): Media
{
    return Media::create([
        'name' => $name,
        'disk' => 'public',
        'path' => "media/{$name}",
        'mime' => 'image/jpeg',
        'size' => 123,
    ]);
}

// The site root as PageSeeder creates it: is_system + no parent is what
// gives it the empty slug path, and therefore the '/' breadcrumb URL.
function serverHeadHome(): Page
{
    return Page::factory()->create([
        'status' => PageStatus::Published,
        'is_system' => true,
        'type' => 'page',
        'name' => ['en' => 'Home'],
        'title' => ['en' => 'Home'],
    ]);
}

it('renders the full head tag set server-side for a plain GET on a published nested page', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    app(Cms::class)->frontendRoutes();
    app(Settings::class)->set('site.name', 'Acme Co');
    $favicon = serverHeadMedia('favicon.png');
    app(Settings::class)->set('site.favicon', (string) $favicon->getKey());
    $ogImage = serverHeadMedia('og.jpg');

    $parent = Page::factory()->category()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Guides', 'uk' => 'Hurydy'],
    ]);
    $page = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $parent->id,
        'name' => ['en' => 'Setup', 'uk' => 'Nalashtuvannya'],
        'title' => ['en' => 'Setup Guide'],
        'summary' => ['en' => 'How to set things up.'],
        'is_indexable' => true,
        'seo' => ['og_image' => (string) $ogImage->getKey()],
    ]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)
        ->toContain('<title data-inertia="">Setup Guide')
        ->toContain('<meta data-inertia="meta-description" name="description" content="How to set things up.">')
        ->toContain('data-inertia="link-canonical" rel="canonical" href="' . url($page->pathFor('en')) . '"')
        ->toContain('data-inertia="og-title" property="og:title" content="Setup Guide"')
        ->toContain('data-inertia="og-site-name" property="og:site_name" content="Acme Co"')
        ->toContain('data-inertia="og-type" property="og:type" content="article"')
        ->toContain('data-inertia="twitter-card" name="twitter:card" content="summary_large_image"')
        ->toContain('data-inertia="alternate-en" rel="alternate" hreflang="en"')
        ->toContain('data-inertia="alternate-uk" rel="alternate" hreflang="uk"')
        ->toContain('data-inertia="alternate-x-default" rel="alternate" hreflang="x-default"')
        ->toContain('data-inertia="link-icon" rel="icon"')
        ->toContain('data-inertia="jsonld-1" type="application/ld+json"')
        ->toContain('"@type":"BreadcrumbList"')
        ->toContain('"name":"Guides"')
        ->toContain('"name":"Setup Guide"');
});

it('omits the hreflang cluster when the page exists in only one locale', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    app(Cms::class)->frontendRoutes();
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'is_indexable' => true,
    ]);
    // PageSlugWriter backfills every frontend-active locale with a fallback
    // slug even without a translated name, so "one locale only" has to be
    // simulated by removing the other locale's slug row directly.
    $page->slugs()->where('locale', 'uk')->delete();

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)->not->toContain('rel="alternate"');
});

it('omits the BreadcrumbList object for a top-level page when no site root is seeded', function (): void {
    app(Cms::class)->frontendRoutes();
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
    ]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)->not->toContain('BreadcrumbList');
});

it('roots the BreadcrumbList at the home page for a top-level page', function (): void {
    app(Cms::class)->frontendRoutes();
    serverHeadHome();
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About'],
    ]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)->toContain('"@type":"BreadcrumbList"')
        ->toContain('"position":1,"name":"Home","item":"' . url('/') . '"')
        ->toContain('"position":2,"name":"About"');
});

it('omits the BreadcrumbList object on the home page itself', function (): void {
    app(Cms::class)->frontendRoutes();
    $home = serverHeadHome();

    $html = $this->get($home->pathFor('en'))->assertOk()->getContent();

    expect($html)->not->toContain('BreadcrumbList');
});

it('prepends home to an existing nested trail without reordering it', function (): void {
    app(Cms::class)->frontendRoutes();
    serverHeadHome();
    $parent = Page::factory()->create([
        'status' => PageStatus::Published,
        'type' => 'category',
        'name' => ['en' => 'Guides'],
        'title' => ['en' => 'Guides'],
    ]);
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'type' => 'article',
        'parent_id' => $parent->getKey(),
        'name' => ['en' => 'Setup Guide'],
        'title' => ['en' => 'Setup Guide'],
    ]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)->toContain('"position":1,"name":"Home"')
        ->toContain('"position":2,"name":"Guides"')
        ->toContain('"position":3,"name":"Setup Guide"');
});

it('labels every BreadcrumbList item by name, not by the SEO-length title', function (): void {
    // Google may show these crumbs in place of the URL; a title like
    // "Camera repair in Dnipro — Autofocus" reads as spam there.
    app(Cms::class)->frontendRoutes();
    Page::factory()->create([
        'status' => PageStatus::Published,
        'is_system' => true,
        'name' => ['en' => 'Home'],
        'title' => ['en' => 'Camera repair in Dnipro — Autofocus'],
    ]);
    $parent = Page::factory()->category()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Services'],
        'title' => ['en' => 'Photo and video repair services in Dnipro'],
    ]);
    $page = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $parent->getKey(),
        'name' => ['en' => 'DSLR cameras'],
        'title' => ['en' => 'DSLR camera repair in Dnipro'],
    ]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)->toContain('"position":1,"name":"Home"')
        ->toContain('"position":2,"name":"Services"')
        ->toContain('"position":3,"name":"DSLR cameras"');
});

it('omits the WebSite object when site.name is empty', function (): void {
    app(Cms::class)->frontendRoutes();
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)->not->toContain('"@type":"WebSite"');
});

it('renders both halves of the GTM snippet when a container id is configured', function (): void {
    app(Cms::class)->frontendRoutes();
    app(Settings::class)->set('seo.gtm_container_id', 'GTM-ABC1234');
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)
        ->toContain("'https://www.googletagmanager.com/gtm.js?id='+i+dl")
        ->toContain("'GTM-ABC1234'")
        ->toContain('<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-ABC1234"');
});

it('renders no GTM markup when the container id is unset or malformed', function (): void {
    app(Cms::class)->frontendRoutes();
    app(Settings::class)->set('seo.gtm_container_id', '"><script>alert(1)</script>');
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)->not->toContain('googletagmanager.com');
});

it('renders the identity JSON-LD object after WebSite and BreadcrumbList', function (): void {
    app(Cms::class)->frontendRoutes();
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set('seo.identity.type', 'organization');
    app(Settings::class)->set('seo.identity.name', 'Acme Organization');
    $parent = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Guides'], 'title' => ['en' => '']]);
    $page = Page::factory()->article()->create(['status' => PageStatus::Published, 'parent_id' => $parent->id, 'name' => ['en' => 'Setup']]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)
        ->toContain('data-inertia="jsonld-0"')
        ->toContain('data-inertia="jsonld-1"')
        ->toContain('data-inertia="jsonld-2"')
        ->toContain('"@type":"Organization"')
        ->toContain('"name":"Acme Organization"');
});

it('filters non-URL entries out of the emitted sameAs structured data', function (): void {
    app(Cms::class)->frontendRoutes();
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set('seo.identity.type', 'organization');
    // Written directly to the store (bypassing the Contacts form's url
    // rule) — emit-time filtering must hold on its own.
    app(Settings::class)->set('site.contacts.socials', [
        ['label' => 'GH', 'href' => 'https://github.com/acme'],
        ['label' => 'X', 'href' => 'not a url'],
        ['label' => 'JS', 'href' => 'javascript:alert(1)'],
    ]);
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    // Scoped to the ld+json payloads: the raw public SEO setting also rides
    // along in the serialized cms.settings prop, where it is inert JSON —
    // only the structured data must be clean.
    preg_match_all('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#s', $html, $matches);
    $jsonLd = implode("\n", $matches[1]);

    expect($jsonLd)
        ->toContain('"sameAs":["https://github.com/acme"]')
        ->not->toContain('javascript:alert');
});

it('renders the robots noindex tag for a noindex page', function (): void {
    app(Cms::class)->frontendRoutes();
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Hidden'],
        'is_indexable' => false,
    ]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)->toContain('data-inertia="meta-robots" name="robots" content="noindex"');
});

it('renders nothing when the root view is reused by a route with no seo prop', function (): void {
    Route::get('_test/plain', fn () => view('server-head-test', [
        'page' => ['component' => 'Plain', 'props' => []],
    ]));

    $html = $this->get('_test/plain')->assertOk()->getContent();

    expect($html)->not->toContain('data-inertia="meta-description"')
        ->not->toContain('og:title');
});
