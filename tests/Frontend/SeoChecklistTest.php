<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Frontend\Seo\SeoChecklist;
use Tbtop\Cms\Frontend\Seo\SeoResolver;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Storage::fake('public');
    Language::factory()->default()->create(['locale' => 'en']);
});

function checklistFor(Page $page): array
{
    return app(SeoChecklist::class)->for($page, 'en');
}

function checkByKey(array $checklist, string $key): array
{
    $found = collect($checklist)->firstWhere('key', $key);
    expect($found)->not->toBeNull();

    return $found;
}

function createOgImage(): Media
{
    return Media::create([
        'name' => 'og.jpg',
        'disk' => 'public',
        'path' => 'media/og.jpg',
        'mime' => 'image/jpeg',
        'size' => 100,
    ]);
}

it('fails the title check when the resolved meta title is empty', function (): void {
    $page = Page::factory()->create(['name' => ['en' => ''], 'title' => ['en' => '']]);

    expect(checkByKey(checklistFor($page), 'title')['status'])->toBe('fail');
});

it('fails a description a template filled in for a page that has none', function (): void {
    // The bug this pins: a template appends the site name, so the RENDERED
    // description is never empty and the page reported green while having
    // nothing of its own. What renders is " — Acme Co" — filler, not content.
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set('seo.description_template', '{description} — {site_name}');

    $page = Page::factory()->create([
        'name' => ['en' => 'About'],
        'summary' => ['en' => ''],
        'seo' => [],
    ]);

    expect(app(SeoResolver::class)->resolve($page, 'en')['description'])->toBe('Acme Co')
        ->and(checkByKey(checklistFor($page), 'description')['status'])->toBe('fail');
});

it('fails a description that only comes from the site-wide default', function (): void {
    // seo.default_description is one string served on every page that has
    // none of its own — for a crawler that is the same duplicate content a
    // template is, so it cannot count as the page having something to say.
    app(Settings::class)->set('seo.default_description', 'A default for the whole site.');

    $page = Page::factory()->create([
        'name' => ['en' => 'About'],
        'summary' => ['en' => ''],
        'seo' => [],
    ]);

    expect(app(SeoResolver::class)->resolve($page, 'en')['description'])
        ->toBe('A default for the whole site.')
        ->and(checkByKey(checklistFor($page), 'description')['status'])->toBe('fail');
});

it('passes title and description the page supplies itself', function (): void {
    app(Settings::class)->set('seo.title_template', '{title} — Acme Co');

    $page = Page::factory()->create([
        'name' => ['en' => 'About'],
        'summary' => ['en' => 'What we do.'],
        'seo' => ['title' => ['en' => 'About our team']],
    ]);

    $checks = checklistFor($page);

    expect(checkByKey($checks, 'title')['status'])->toBe('pass')
        ->and(checkByKey($checks, 'description')['status'])->toBe('pass');
});

it('finds a focus keyword the rendered title carries, wherever it came from', function (): void {
    // Deliberately the opposite rule from the presence checks above: "is this
    // filled in?" is about the page, but "does the title carry the keyword?"
    // is about what a crawler reads — and it reads the rendered string.
    app(Settings::class)->set('site.name', 'Widgets Inc');
    app(Settings::class)->set('seo.title_template', '{title} — {site_name}');

    $page = Page::factory()->create([
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About'],
        'seo' => ['focus_keyword' => ['en' => 'Widgets']],
    ]);

    expect(app(SeoResolver::class)->resolve($page, 'en')['title'])->toBe('About — Widgets Inc')
        ->and(checkByKey(checklistFor($page), 'focus_keyword_in_title')['status'])->toBe('pass');
});

it('has no fail checks for a page with a good title, description, and og image', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'A well optimized page'],
        'title' => ['en' => 'A well optimized page with a reasonable length title'],
        'summary' => ['en' => str_repeat('A concise, useful summary of the page content. ', 2)],
        'seo' => ['og_image' => (string) createOgImage()->getKey()],
        'is_indexable' => true,
    ]);

    $statuses = collect(checklistFor($page))->pluck('status');

    expect($statuses)->not->toContain('fail');
});

it('warns on the og image check when the media row has no path', function (): void {
    // UploadUrl::make() would build a bare "/storage/" from an empty path,
    // which is a directory, not an image — passing that as "an OG image is
    // set" is the false reassurance this checklist exists to prevent.
    $pathless = Media::create([
        'name' => 'broken.jpg',
        'disk' => 'public',
        'path' => '',
        'mime' => 'image/jpeg',
        'size' => 0,
    ]);

    $page = Page::factory()->create([
        'name' => ['en' => 'A page'],
        'seo' => ['og_image' => (string) $pathless->getKey()],
    ]);

    expect(checkByKey(checklistFor($page), 'og_image')['status'])->toBe('warn');
});

it('passes the canonical check when the override differs only by host casing', function (): void {
    // Hostnames are case-insensitive; parse_url() preserves whatever casing
    // it was handed, so this must not be reported as pointing off-site.
    $host = parse_url((string) url('/'), PHP_URL_HOST);
    $page = Page::factory()->create([
        'name' => ['en' => 'A page'],
        'seo' => ['canonical' => ['en' => 'https://' . mb_strtoupper((string) $host) . '/some-path']],
    ]);

    expect(checkByKey(checklistFor($page), 'canonical')['status'])->toBe('pass');
});

it('warns on the canonical check when the override points at another host', function (): void {
    $page = Page::factory()->create([
        'name' => ['en' => 'A page'],
        'seo' => ['canonical' => ['en' => 'https://somewhere-else.example/some-path']],
    ]);

    expect(checkByKey(checklistFor($page), 'canonical')['status'])->toBe('warn');
});

it('passes focus_keyword_in_title when the keyword is present in the title', function (): void {
    $page = Page::factory()->create([
        'name' => ['en' => 'Widgets'],
        'title' => ['en' => 'Buy premium widgets online'],
        'seo' => ['focus_keyword' => ['en' => 'widgets']],
    ]);

    expect(checkByKey(checklistFor($page), 'focus_keyword_in_title')['status'])->toBe('pass');
});

it('fails focus_keyword_in_title when the keyword is absent from the title', function (): void {
    $page = Page::factory()->create([
        'name' => ['en' => 'Widgets'],
        'title' => ['en' => 'Buy premium gadgets online'],
        'seo' => ['focus_keyword' => ['en' => 'widgets']],
    ]);

    expect(checkByKey(checklistFor($page), 'focus_keyword_in_title')['status'])->toBe('fail');
});

it('warns when another page resolves to the same meta title', function (): void {
    Page::factory()->create(['name' => ['en' => 'Other'], 'seo' => ['title' => ['en' => 'Shared title']]]);
    $page = Page::factory()->create(['name' => ['en' => 'Mine'], 'seo' => ['title' => ['en' => 'Shared title']]]);

    expect(checkByKey(checklistFor($page), 'duplicate_title')['status'])->toBe('warn');
});

it('does not warn about a duplicate title when every other page differs', function (): void {
    Page::factory()->create(['name' => ['en' => 'Other'], 'seo' => ['title' => ['en' => 'Their title']]]);
    $page = Page::factory()->create(['name' => ['en' => 'Mine'], 'seo' => ['title' => ['en' => 'My title']]]);

    expect(checkByKey(checklistFor($page), 'duplicate_title')['status'])->toBe('pass');
});

it('warns when a title-omitting template makes distinct page titles collide', function (): void {
    app(Settings::class)->set('site.name', 'Acme');
    app(Settings::class)->set('seo.title_template', '{site_name}');
    Page::factory()->create(['name' => ['en' => 'One'], 'title' => ['en' => 'One']]);
    $page = Page::factory()->create(['name' => ['en' => 'Two'], 'title' => ['en' => 'Two']]);

    expect(checkByKey(checklistFor($page), 'duplicate_title')['status'])->toBe('warn');
});

it('resolves no ancestors per page when the title template omits {parent}', function (): void {
    // The duplicate check renders every other page's title, and rendering
    // {parent} costs a recursive ancestor CTE per page. Templates without
    // {parent} must not pay it — this pins the count so an eager
    // breadcrumb resolve cannot creep back in as a silent N+1.
    app(Settings::class)->set('seo.title_template', '{title} {sep} {site_name}');

    foreach (range(1, 5) as $i) {
        $parent = Page::factory()->create(['type' => 'category', 'name' => ['en' => "C{$i}"]]);
        Page::factory()->create(['type' => 'article', 'parent_id' => $parent->id, 'name' => ['en' => "A{$i}"]]);
    }

    $page = Page::factory()->create(['name' => ['en' => 'Mine']]);

    DB::enableQueryLog();
    checklistFor($page);

    $ancestorQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'WITH RECURSIVE'))
        ->count();

    expect($ancestorQueries)->toBe(0);
});

it('detects a duplicate that only collides further down the title cascade', function (): void {
    // The check walks seo.title → title → name itself rather than calling
    // resolve() per page; this pins it to the same cascade, catching a
    // collision where one page sets seo.title and the other only a name.
    Page::factory()->create(['name' => ['en' => 'Contact us'], 'title' => ['en' => '']]);
    $page = Page::factory()->create(['name' => ['en' => 'Mine'], 'seo' => ['title' => ['en' => 'Contact us']]]);

    expect(checkByKey(checklistFor($page), 'duplicate_title')['status'])->toBe('warn');
});

it('keeps the duplicate check aligned with the title the resolver renders', function (): void {
    // A site-wide template must not desync the two: the check compares
    // untemplated bases while the resolver renders the templated title, and
    // both must still agree that these two pages collide.
    app(Settings::class)->set('seo.title_template', '{title} | Acme');
    Page::factory()->create(['name' => ['en' => 'Other'], 'seo' => ['title' => ['en' => 'Same']]]);
    $page = Page::factory()->create(['name' => ['en' => 'Mine'], 'seo' => ['title' => ['en' => 'Same']]]);

    expect(app(SeoResolver::class)->resolve($page, 'en')['title'])->toBe('Same | Acme')
        ->and(checkByKey(checklistFor($page), 'duplicate_title')['status'])->toBe('warn');
});

it('does not warn about a duplicate description when every other page has a distinct rendered description', function (): void {
    Page::factory()->create(['name' => ['en' => 'Other'], 'summary' => ['en' => 'Their summary.']]);
    $page = Page::factory()->create(['name' => ['en' => 'Mine'], 'summary' => ['en' => 'My summary.']]);

    expect(checkByKey(checklistFor($page), 'duplicate_description')['status'])->toBe('pass');
});

it('passes the duplicate description check when the page has no description at all', function (): void {
    $page = Page::factory()->create(['name' => ['en' => 'Mine'], 'summary' => ['en' => ''], 'seo' => []]);

    expect(checkByKey(checklistFor($page), 'duplicate_description')['status'])->toBe('pass');
});

it('warns when a description-omitting template makes distinct page descriptions collide', function (): void {
    app(Settings::class)->set('seo.description_template', 'Read more on our site.');
    Page::factory()->create(['name' => ['en' => 'One'], 'summary' => ['en' => 'About one.']]);
    $page = Page::factory()->create(['name' => ['en' => 'Two'], 'summary' => ['en' => 'About two.']]);

    expect(checkByKey(checklistFor($page), 'duplicate_description')['status'])->toBe('warn');
});

it('warns when two pages with no own description share the site-wide default description', function (): void {
    // The main real-world case this check exists for: a set of empty pages
    // all falling back to seo.default_description resolve to the exact same
    // meta description, which is duplicate content even though neither page
    // "did" anything wrong on its own.
    app(Settings::class)->set('seo.default_description', 'A default for the whole site.');
    Page::factory()->create(['name' => ['en' => 'One'], 'summary' => ['en' => ''], 'seo' => []]);
    $page = Page::factory()->create(['name' => ['en' => 'Two'], 'summary' => ['en' => ''], 'seo' => []]);

    expect(checkByKey(checklistFor($page), 'duplicate_description')['status'])->toBe('warn');
});
