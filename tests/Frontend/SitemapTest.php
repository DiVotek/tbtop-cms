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
    Language::factory()->default()->create(['locale' => 'en']);
});

it('lists published indexable pages with per-locale urls and hreflang alternates', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    app(Cms::class)->frontendRoutes();

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        'is_indexable' => true,
    ]);

    $enUrl = url($page->pathFor('en'));
    $ukUrl = url('uk/' . $page->pathFor('uk'));

    $response = $this->get('/sitemap.xml')->assertOk();
    $response->assertHeader('Content-Type', 'application/xml');

    $xml = new SimpleXMLElement($response->getContent());
    $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $xml->registerXPathNamespace('xhtml', 'http://www.w3.org/1999/xhtml');

    $locs = array_map(static fn (SimpleXMLElement $node): string => (string) $node, $xml->xpath('//s:url/s:loc'));

    expect($locs)->toContain($enUrl)->toContain($ukUrl);

    $enAlternates = $xml->xpath("//s:url[s:loc='{$enUrl}']/xhtml:link");
    $hreflangs = array_map(
        static fn (SimpleXMLElement $link): string => (string) $link->attributes()['hreflang'],
        $enAlternates,
    );

    expect($hreflangs)->toContain('en')->toContain('uk')->toContain('x-default');
});

it('excludes draft and non-indexable pages', function (): void {
    app(Cms::class)->frontendRoutes();

    Page::factory()->create(['status' => PageStatus::Draft, 'name' => ['en' => 'Draft']]);
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Hidden'],
        'is_indexable' => false,
    ]);

    $response = $this->get('/sitemap.xml')->assertOk();

    expect($response->getContent())->not->toContain('draft')->not->toContain('hidden');
});

it('returns 404 for sitemap but keeps robots reachable when the sitemap config disables it', function (): void {
    config(['tbtop-cms.seo.sitemap.enabled' => false]);
    app(Cms::class)->frontendRoutes();

    $this->get('/sitemap.xml')->assertNotFound();

    $response = $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('User-agent: *', false)
        ->assertSee('Allow: /', false)
        ->assertDontSee('Sitemap:', false);
});

it('serves robots.txt with a sitemap reference', function (): void {
    app(Cms::class)->frontendRoutes();

    $response = $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('User-agent: *', false)
        ->assertSee('Allow: /', false)
        ->assertSee(url('sitemap.xml'), false);

    expect(strtolower((string) $response->headers->get('Content-Type')))
        ->toBe('text/plain; charset=utf-8');
});

it('disallows all crawling and 404s the sitemap when indexing is disabled', function (): void {
    app(Settings::class)->set('seo.indexing_enabled', false);
    app(Cms::class)->frontendRoutes();

    $this->get('/sitemap.xml')->assertNotFound();

    $response = $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('User-agent: *', false)
        ->assertSee('Disallow: /', false)
        ->assertDontSee('Sitemap:', false)
        ->assertDontSee('Allow: /', false);
});

it('allows indexing by default when the setting is absent', function (): void {
    app(Cms::class)->frontendRoutes();

    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Allow: /', false)
        ->assertDontSee('Disallow: /', false);

    $this->get('/sitemap.xml')->assertOk();
});
