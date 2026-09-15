<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Frontend\Seo\SitemapEntryBuilder;
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

    $this->get('/sitemap.xml', ['X-Inertia' => 'true'])->assertNotFound();

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

    $this->get('/sitemap.xml', ['X-Inertia' => 'true'])->assertNotFound();

    $response = $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('User-agent: *', false)
        ->assertSee('Disallow: /', false)
        ->assertDontSee('Sitemap:', false)
        ->assertDontSee('Allow: /', false);
});

it('allows indexing by default when the setting is absent', function (): void {
    app(Cms::class)->frontendRoutes();

    $body = (string) $this->get('/robots.txt')->assertOk()->getContent();

    // The exact line, not the substring: the owned group also carries
    // `Disallow: /admin` and friends, which share the prefix.
    expect($body)->toContain('Allow: /')
        ->and(preg_split('/\R/', $body))->not->toContain('Disallow: /');

    $this->get('/sitemap.xml')->assertOk();
});

it('puts custom robots rules inside the user-agent group, with sitemap last', function (): void {
    app(Cms::class)->frontendRoutes();
    app(Settings::class)->set('seo.robots_extra', "# our own note\nCrawl-delay: 10");

    $body = $this->get('/robots.txt')->assertOk()->getContent();

    // A blank line ends a robots group, so a custom block separated from
    // "User-agent: *" would parse as a record with no agent and be discarded —
    // present in the file, ignored by the crawler. The group has to stay
    // unbroken from the agent line down to the last custom rule.
    $group = explode("\n\n", $body)[0];

    expect($group)->toContain('User-agent: *')
        ->and($group)->toContain('Allow: /')
        ->and($group)->toContain('# our own note')
        ->and($group)->toContain('Crawl-delay: 10')
        // Sitemap is a top-level directive belonging to no agent, so it trails
        // the group rather than sitting inside it.
        ->and($group)->not->toContain('Sitemap:')
        ->and($body)->toContain('Sitemap: ' . url('sitemap.xml'));
});

it('drops blank lines inside the custom block so nothing falls out of the group', function (): void {
    app(Cms::class)->frontendRoutes();
    app(Settings::class)->set('seo.robots_extra', "Crawl-delay: 10\n\nClean-param: utm_source");

    $body = $this->get('/robots.txt')->assertOk()->getContent();
    $group = explode("\n\n", $body)[0];

    // An editor spacing out their rules would otherwise end the record, and
    // everything below the gap would be ignored by the crawler.
    expect($group)->toContain('Crawl-delay: 10')
        ->and($group)->toContain('Clean-param: utm_source');
});

it('keeps a chunk whole when the locale count exceeds the budget', function (): void {
    // A 1-URL budget cannot be honoured on a two-locale site: the page's
    // locales are never split across files, so the chunk overshoots by design
    // rather than emitting an empty one.
    config(['tbtop-cms.seo.sitemap.per_chunk' => 1]);
    Language::factory()->create(['locale' => 'uk']);
    app(Cms::class)->frontendRoutes();

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About', 'uk' => 'Pro nas'],
        'is_indexable' => true,
    ]);

    expect(substr_count($this->get('/sitemap-1.xml')->assertOk()->getContent(), '<loc>'))->toBe(2);
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
        ->and(preg_split('/\R/', (string) $body))->not->toContain('Disallow: /');
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

it('clamps a stored chunk size above the protocol ceiling', function (): void {
    // The settings form caps this at 50 000, but the value can also arrive
    // from a host config or a row written straight to the database, and a
    // chunk past the limit is rejected wholesale by the crawler reading it.
    app(Settings::class)->set('seo.sitemap_per_chunk', 999999);

    expect(app(SitemapEntryBuilder::class)->defaultPerChunk())->toBe(50000);
});

it('404s a chunk beyond the last one', function (): void {
    app(Cms::class)->frontendRoutes();
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About'], 'is_indexable' => true]);

    $this->get('/sitemap-2.xml', ['X-Inertia' => 'true'])->assertNotFound();
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

it('excludes a locale canonicalized to an external domain', function (): void {
    app(Cms::class)->frontendRoutes();

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Syndicated'],
        'is_indexable' => true,
        'seo' => ['canonical' => ['en' => 'https://elsewhere.example/original']],
    ]);

    // A conflicting canonical is a signal not to index this address at all —
    // leaving it in the sitemap next to a canonical pointing off-site would
    // tell the crawler two contradictory things about the same URL.
    expect($this->get('/sitemap-1.xml')->assertOk()->getContent())
        ->not->toContain('syndicated');
});

it('excludes a locale canonicalized to another internal page', function (): void {
    app(Cms::class)->frontendRoutes();

    $canonicalPage = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Canonical Target'],
        'is_indexable' => true,
    ]);
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Duplicate'],
        'is_indexable' => true,
        'seo' => ['canonical' => ['en' => url($canonicalPage->pathFor('en'))]],
    ]);

    $xml = new SimpleXMLElement($this->get('/sitemap-1.xml')->assertOk()->getContent());
    $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $locs = array_map(static fn (SimpleXMLElement $node): string => (string) $node, $xml->xpath('//s:url/s:loc'));

    expect($locs)->toContain(url($canonicalPage->pathFor('en')))
        ->not->toContain(url('duplicate'));
});

it('keeps a self-canonical entry regardless of trailing slash or host case', function (): void {
    app(Cms::class)->frontendRoutes();

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Self Canonical'],
        'is_indexable' => true,
    ]);
    $ownUrl = url($page->pathFor('en'));
    $host = parse_url($ownUrl, PHP_URL_HOST);
    $selfCanonical = str_replace((string) $host, mb_strtoupper((string) $host), $ownUrl) . '/';
    $page->update(['seo' => ['canonical' => ['en' => $selfCanonical]]]);

    $xml = new SimpleXMLElement($this->get('/sitemap-1.xml')->assertOk()->getContent());
    $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $locs = array_map(static fn (SimpleXMLElement $node): string => (string) $node, $xml->xpath('//s:url/s:loc'));

    expect($locs)->toContain($ownUrl);
});

it('drops the canonicalized-away locale from other locales alternates too, keeping the rest', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    app(Cms::class)->frontendRoutes();

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Partly Canonical', 'uk' => 'Chastkovo Kanonichna'],
        'is_indexable' => true,
        'seo' => ['canonical' => ['en' => 'https://elsewhere.example/original', 'uk' => '']],
    ]);

    $enUrl = url($page->pathFor('en'));
    $ukUrl = url('uk/' . $page->pathFor('uk'));

    $xml = new SimpleXMLElement($this->get('/sitemap-1.xml')->assertOk()->getContent());
    $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $xml->registerXPathNamespace('xhtml', 'http://www.w3.org/1999/xhtml');

    $locs = array_map(static fn (SimpleXMLElement $node): string => (string) $node, $xml->xpath('//s:url/s:loc'));
    // The English URL itself is gone (canonicalized away)...
    expect($locs)->toContain($ukUrl)->not->toContain($enUrl);

    // ...and so is any hreflang alternate pointing an English visitor at it.
    $ukAlternates = $xml->xpath("//s:url[s:loc='{$ukUrl}']/xhtml:link");
    $hreflangs = array_map(
        static fn (SimpleXMLElement $link): string => (string) $link->attributes()['hreflang'],
        $ukAlternates,
    );

    expect($hreflangs)->not->toContain('en')->toContain('uk');
});

it('gives no entries at all for a page whose every locale is canonicalized away', function (): void {
    Language::factory()->create(['locale' => 'uk']);
    app(Cms::class)->frontendRoutes();

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Fully Canonical', 'uk' => 'Povnistiu Kanonichna'],
        'is_indexable' => true,
        'seo' => ['canonical' => [
            'en' => 'https://elsewhere.example/en',
            'uk' => 'https://elsewhere.example/uk',
        ]],
    ]);

    $body = $this->get('/sitemap-1.xml')->assertOk()->getContent();

    expect($body)->not->toContain('fully-canonical')
        ->and(substr_count($body, '<loc>'))->toBe(0);
});

it('does not let a page with every locale canonicalized away shrink the chunk count', function (): void {
    // Row-window pagination is by page, not by surviving entry count — a page
    // contributing zero URLs still consumes one row in the window, same as a
    // page with no slug in any active locale does today.
    config(['tbtop-cms.seo.sitemap.per_chunk' => 2]);
    app(Cms::class)->frontendRoutes();

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Canonical Away'],
        'is_indexable' => true,
        'seo' => ['canonical' => ['en' => 'https://elsewhere.example/away']],
    ]);
    foreach (['Two', 'Three'] as $name) {
        Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => $name], 'is_indexable' => true]);
    }

    $index = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($index)->toContain(url('sitemap-1.xml'))->toContain(url('sitemap-2.xml'));

    $first = $this->get('/sitemap-1.xml')->assertOk()->getContent();
    $second = $this->get('/sitemap-2.xml')->assertOk()->getContent();

    // Three page rows over a 2-page-per-chunk window: chunk 1 holds the
    // canonicalized-away page (0 URLs) plus "Two" (1 URL); chunk 2 holds
    // "Three" alone.
    expect(substr_count($first, '<loc>'))->toBe(1)
        ->and(substr_count($second, '<loc>'))->toBe(1);
});

it('treats a stored canonical with a non-http(s) scheme as absent, keeping the entry', function (): void {
    app(Cms::class)->frontendRoutes();

    // Written straight to the database: the form rule (url:http,https) only
    // guards what an editor types going forward, but a row can already carry
    // a scheme the rule now rejects.
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Legacy Scheme'],
        'is_indexable' => true,
        'seo' => ['canonical' => ['en' => 'javascript:alert(1)']],
    ]);

    expect($this->get('/sitemap-1.xml')->assertOk()->getContent())
        ->toContain(url('legacy-scheme'));
});

it('treats a stored canonical with an http scheme but no host as absent, keeping the entry', function (): void {
    app(Cms::class)->frontendRoutes();

    // parse_url('http:foo') reports an http scheme, but without a host the
    // value is not an absolute URL a crawler can follow — it must not count
    // as a canonical override.
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Hostless Scheme'],
        'is_indexable' => true,
        'seo' => ['canonical' => ['en' => 'http:foo']],
    ]);

    expect($this->get('/sitemap-1.xml')->assertOk()->getContent())
        ->toContain(url('hostless-scheme'));
});

it('excludes an entry whose canonical differs only by a repeated trailing slash', function (): void {
    app(Cms::class)->frontendRoutes();

    // A single trailing slash is cosmetic, but "/foo//" names a different
    // path on servers where repeated slashes matter — that override must
    // count as non-self and drop the entry.
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Double Slash'],
        'is_indexable' => true,
        'seo' => ['canonical' => ['en' => url('double-slash') . '//']],
    ]);

    expect($this->get('/sitemap-1.xml')->assertOk()->getContent())
        ->not->toContain(url('double-slash'));
});

it('excludes an entry whose canonical differs only by userinfo or fragment', function (string $suffix, string $slug): void {
    app(Cms::class)->frontendRoutes();

    // Userinfo and fragment are part of what the override names; an address
    // carrying either is not the entry's own URL even when scheme, host and
    // path all match.
    $own = url($slug);
    $canonical = match ($suffix) {
        'userinfo' => str_replace('://', '://user:secret@', $own),
        'fragment' => $own . '#section',
    };

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => ucfirst($slug)],
        'is_indexable' => true,
        'seo' => ['canonical' => ['en' => $canonical]],
    ]);

    expect($this->get('/sitemap-1.xml')->assertOk()->getContent())
        ->not->toContain($own);
})->with([
    'userinfo' => ['userinfo', 'userinfo-page'],
    'fragment' => ['fragment', 'fragment-page'],
]);
