<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Admin\Media\Models\Media;
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

    $response = $this->get('/sitemap-1.xml')->assertOk();
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

    $response = $this->get('/sitemap-1.xml')->assertOk();

    expect($response->getContent())->not->toContain('draft')->not->toContain('hidden');
});

it('excludes a scheduled page whose published_at is in the future', function (): void {
    app(Cms::class)->frontendRoutes();

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Scheduled'],
        'is_indexable' => true,
        'published_at' => now()->addDay(),
    ]);

    $response = $this->get('/sitemap-1.xml')->assertOk();

    expect($response->getContent())->not->toContain('scheduled');
});

it('excludes an expired page whose unpublish_at is in the past', function (): void {
    app(Cms::class)->frontendRoutes();

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Expired'],
        'is_indexable' => true,
        'unpublish_at' => now()->subDay(),
    ]);

    $response = $this->get('/sitemap-1.xml')->assertOk();

    expect($response->getContent())->not->toContain('expired');
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

it('appends custom robots rules below the ones it writes itself', function (): void {
    app(Cms::class)->frontendRoutes();
    app(Settings::class)->set('seo.robots_extra', "# our own note\nCrawl-delay: 10");

    $body = $this->get('/robots.txt')->assertOk()->getContent();

    // Order matters: a crawler reads top to bottom, and the owned rules are
    // the ones the rest of the admin decided.
    expect($body)->toContain('User-agent: *')
        ->and($body)->toContain('Crawl-delay: 10')
        ->and($body)->toContain('# our own note')
        ->and(mb_strpos($body, 'Allow: /'))->toBeLessThan(mb_strpos($body, 'Crawl-delay: 10'));
});

it('drops a custom line that claims a directive the site owns', function (): void {
    app(Cms::class)->frontendRoutes();
    // Stored directly rather than through the settings form, which rejects
    // this outright — a value already in the database (hand-edited, or saved
    // before the rule existed) must not be able to override the owned half.
    app(Settings::class)->set('seo.robots_extra', "Disallow: /\nCrawl-delay: 5");

    $body = $this->get('/robots.txt')->assertOk()->getContent();

    expect($body)->toContain('Allow: /')
        ->and($body)->toContain('Crawl-delay: 5')
        ->and($body)->not->toContain('Disallow: /');
});

it('keeps the disallow-all block intact when indexing is off, custom rules and all', function (): void {
    app(Settings::class)->set('seo.indexing_enabled', false);
    app(Settings::class)->set('seo.robots_extra', 'Crawl-delay: 5');
    app(Cms::class)->frontendRoutes();

    $body = $this->get('/robots.txt')->assertOk()->getContent();

    expect($body)->toContain('Disallow: /')
        ->and($body)->not->toContain('Allow: /')
        ->and($body)->not->toContain('Sitemap:')
        ->and($body)->toContain('Crawl-delay: 5');
});

it('serves sitemap.xml as an index of chunks, never as a urlset', function (): void {
    app(Cms::class)->frontendRoutes();
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About'], 'is_indexable' => true]);

    $body = $this->get('/sitemap.xml')->assertOk()->getContent();

    // The document type is what tells a crawler whether to expect URLs or more
    // sitemaps, so a one-chunk site must still answer with an index — growing
    // past one chunk then adds entries instead of changing the shape.
    expect($body)->toContain('<sitemapindex')
        ->not->toContain('<urlset')
        ->toContain(url('sitemap-1.xml'));
});

it('splits pages across chunks once they exceed the per-chunk budget', function (): void {
    config(['tbtop-cms.seo.sitemap.per_chunk' => 2]);
    app(Cms::class)->frontendRoutes();

    foreach (['One', 'Two', 'Three'] as $name) {
        Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => $name], 'is_indexable' => true]);
    }

    $index = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($index)->toContain(url('sitemap-1.xml'))->toContain(url('sitemap-2.xml'));

    $first = $this->get('/sitemap-1.xml')->assertOk()->getContent();
    $second = $this->get('/sitemap-2.xml')->assertOk()->getContent();

    expect(substr_count($first, '<loc>'))->toBe(2)
        ->and(substr_count($second, '<loc>'))->toBe(1);
});

it('sizes chunks by url count, not row count, so locales multiply into the budget', function (): void {
    // Two locales means each page is two URLs, so a 2-URL budget fits one page.
    config(['tbtop-cms.seo.sitemap.per_chunk' => 2]);
    Language::factory()->create(['locale' => 'uk']);
    app(Cms::class)->frontendRoutes();

    foreach (['One', 'Two'] as $name) {
        Page::factory()->create([
            'status' => PageStatus::Published,
            'name' => ['en' => $name, 'uk' => $name],
            'is_indexable' => true,
        ]);
    }

    expect(substr_count($this->get('/sitemap-1.xml')->assertOk()->getContent(), '<loc>'))->toBe(2)
        ->and(substr_count($this->get('/sitemap-2.xml')->assertOk()->getContent(), '<loc>'))->toBe(2);
});

it('lets the admin setting override the config default for chunk size', function (): void {
    config(['tbtop-cms.seo.sitemap.per_chunk' => 1000]);
    app(Settings::class)->set('seo.sitemap_per_chunk', 2);
    app(Cms::class)->frontendRoutes();

    foreach (['One', 'Two', 'Three'] as $name) {
        Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => $name], 'is_indexable' => true]);
    }

    // Three pages would be one chunk under the config default; the stored
    // setting is what splits them.
    expect($this->get('/sitemap.xml')->assertOk()->getContent())
        ->toContain(url('sitemap-2.xml'));
});

it('404s a chunk beyond the last one', function (): void {
    app(Cms::class)->frontendRoutes();
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About'], 'is_indexable' => true]);

    $this->get('/sitemap-2.xml')->assertNotFound();
});

it('gives the home page the top priority and nests the rest below it', function (): void {
    app(Cms::class)->frontendRoutes();

    $parent = Page::factory()->create([
        'type' => 'category',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Blog'],
        'is_indexable' => true,
    ]);
    Page::factory()->create([
        'type' => 'article',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Post'],
        'parent_id' => $parent->id,
        'is_indexable' => true,
    ]);

    $xml = new SimpleXMLElement($this->get('/sitemap-1.xml')->assertOk()->getContent());
    $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    $priorityFor = static function (string $loc) use ($xml): string {
        $nodes = $xml->xpath("//s:url[s:loc='{$loc}']/s:priority");

        return $nodes === false || $nodes === [] ? '' : (string) $nodes[0];
    };

    expect($priorityFor(url('blog')))->toBe('0.8')
        ->and($priorityFor(url('blog/post')))->toBe('0.6');
});

it('gives the home page the top priority', function (): void {
    app(Cms::class)->frontendRoutes();

    // The home page is the one with an empty path — cms:install seeds it, so a
    // test database has to stand one up itself.
    $home = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Home'], 'is_indexable' => true]);
    $home->slugs()->update(['slug' => '', 'path' => '']);

    $xml = new SimpleXMLElement($this->get('/sitemap-1.xml')->assertOk()->getContent());
    $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    $nodes = $xml->xpath('//s:url[s:loc="' . url('/') . '"]/s:priority');

    expect($nodes)->not->toBeEmpty()
        ->and((string) $nodes[0])->toBe('1.0');
});

it("lists the page's own image", function (): void {
    app(Cms::class)->frontendRoutes();

    $media = Media::create(['name' => 'hero.png', 'disk' => 'public', 'path' => 'media/hero.png', 'mime' => 'image/png', 'size' => 10]);
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Illustrated'],
        'is_indexable' => true,
        'image' => (string) $media->id,
    ]);

    // Absolute, not the root-relative path UploadUrl stores: a sitemap is read
    // away from the site that served it and has no origin to resolve against.
    expect($this->get('/sitemap-1.xml')->assertOk()->getContent())
        ->toContain('<image:image>')
        ->toContain(url('/storage/media/hero.png'));
});

it('never lists the site-wide default image', function (): void {
    app(Cms::class)->frontendRoutes();

    $media = Media::create(['name' => 'default.png', 'disk' => 'public', 'path' => 'media/default.png', 'mime' => 'image/png', 'size' => 10]);
    app(Settings::class)->set('seo.default_og_image', (string) $media->id);

    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Bare'], 'is_indexable' => true]);

    // A default shared by every page would tell a crawler one image belongs to
    // the whole site, which is worse than listing none.
    expect($this->get('/sitemap-1.xml')->assertOk()->getContent())
        ->not->toContain('<image:image>');
});
