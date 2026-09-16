<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

// Plain GETs render through the @vite-free root view — see
// workbench/resources/views/server-head-test.blade.php's docblock, same
// setup as ServerHeadTest.php/SeoVerificationTest.php.
beforeEach(function (): void {
    config(['cache.default' => 'array', 'tbtop-cms.render.root_view' => 'server-head-test']);
    Storage::fake('public');
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

function articleMeta(string $name, array $overrides = []): Media
{
    return Media::create([
        'name' => $name,
        'disk' => 'public',
        'path' => "media/{$name}",
        'mime' => 'image/jpeg',
        'size' => 123,
        ...$overrides,
    ]);
}

// ArticleType::requiresParent() — every article fixture needs one, same as
// ServerHeadTest.php's category+article pairing.
function articleParentId(): int
{
    return Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Blog']])->id;
}

it('renders article og:type and article:* tags for a live article with author', function (): void {
    app(Settings::class)->set('seo.identity.type', 'person');
    app(Settings::class)->set('seo.identity.name', 'Jane Doe');
    $page = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => articleParentId(),
        'name' => ['en' => 'My Post'],
        'title' => ['en' => 'My Post'],
        'published_at' => '2024-01-15 10:00:00',
    ]);
    $page->forceFill(['updated_at' => '2024-02-01 12:00:00'])->save();
    $page->refresh();

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)
        ->toContain('data-inertia="og-type" property="og:type" content="article"')
        ->toContain('data-inertia="article-published-time" property="article:published_time" content="' . $page->published_at->toIso8601String() . '"')
        ->toContain('data-inertia="article-modified-time" property="article:modified_time" content="' . $page->updated_at->toIso8601String() . '"')
        ->toContain('data-inertia="article-author" property="article:author" content="Jane Doe"');
});

it('renders og:type website and no article:* tags for a non-article page', function (): void {
    app(Settings::class)->set('seo.identity.type', 'person');
    app(Settings::class)->set('seo.identity.name', 'Jane Doe');
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'published_at' => '2024-01-15 10:00:00',
    ]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)
        ->toContain('data-inertia="og-type" property="og:type" content="website"')
        ->not->toContain('article:published_time')
        ->not->toContain('article:modified_time')
        ->not->toContain('article:author');
});

it('omits article:published_time for a draft article, even reached through admin preview', function (): void {
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    $page = Page::factory()->article()->create([
        'status' => PageStatus::Draft,
        'parent_id' => articleParentId(),
        'name' => ['en' => 'Draft Post'],
        'published_at' => '2024-01-15 10:00:00',
    ]);

    // A draft 404s for an anonymous visitor (see PagePreviewTest.php), so the
    // isLive() gate on articlePublishedTime can only be exercised through
    // admin preview access — the same route that reaches SchemaContext::node()'s
    // matching gate.
    $this->actingAs($user, 'web')
        ->get('/' . $page->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.ogType', 'article')
        ->assertJsonPath('props.seo.articlePublishedTime', null);
});

it('omits article:author when the article has no author set', function (): void {
    $page = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => articleParentId(),
        'name' => ['en' => 'No Author'],
        'seo' => [],
    ]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)->not->toContain('article:author');
});

it('renders og:image:alt, og:image:type and twitter:image:alt for a page image with alt text', function (): void {
    $image = articleMeta('hero.jpg', ['alt' => 'A scenic hero image']);
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'image' => (string) $image->getKey(),
    ]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)
        ->toContain('data-inertia="og-image-alt" property="og:image:alt" content="A scenic hero image"')
        ->toContain('data-inertia="og-image-type" property="og:image:type" content="image/jpeg"')
        ->toContain('data-inertia="twitter-image-alt" name="twitter:image:alt" content="A scenic hero image"');
});

it('omits og:image:alt and twitter:image:alt when the media row has no alt text', function (): void {
    $image = articleMeta('no-alt.jpg');
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'image' => (string) $image->getKey(),
    ]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)
        ->not->toContain('og:image:alt')
        ->not->toContain('twitter:image:alt')
        ->toContain('data-inertia="og-image-type" property="og:image:type" content="image/jpeg"');
});

it('omits og:image:type when the resolved media is not an image mime', function (): void {
    $document = articleMeta('brochure.pdf', ['mime' => 'application/pdf', 'alt' => 'Brochure']);
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'image' => (string) $document->getKey(),
    ]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)
        ->not->toContain('og:image:type')
        ->toContain('data-inertia="og-image-alt" property="og:image:alt" content="Brochure"');
});

it('omits every og:image:* tag when the page has no image and no global default', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
    ]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)
        ->not->toContain('og:image"')
        ->not->toContain('og:image:alt')
        ->not->toContain('og:image:type')
        ->not->toContain('twitter:image:alt');
});

it('follows the global default og image for alt and type when the page has none of its own', function (): void {
    $default = articleMeta('default-og.jpg', ['alt' => 'Default share image']);
    app(Settings::class)->set('seo.default_og_image', (string) $default->getKey());
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
    ]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    expect($html)
        ->toContain('data-inertia="og-image" property="og:image" content="' . url(Storage::disk('public')->url('media/default-og.jpg')) . '"')
        ->toContain('data-inertia="og-image-alt" property="og:image:alt" content="Default share image"')
        ->toContain('data-inertia="og-image-type" property="og:image:type" content="image/jpeg"');
});
