<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Frontend\PreviewLink;
use Tbtop\Cms\Frontend\Seo\SeoTemplates;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set(SeoTemplates::key('article', 'title'), '{title} {sep} {parent} {sep} {site_name}');
    app(Cms::class)->frontendRoutes();
});

/**
 * A system page's status is locked, so a non-live root is an expired one
 * (unpublish_at in the past).
 */
function breadcrumbsHome(bool $live = true): Page
{
    // The seeded home is a sibling of top-level pages, not their parent.
    return Page::factory()->create([
        'status' => PageStatus::Published,
        'unpublish_at' => $live ? null : now()->subDay(),
        'is_system' => true,
        'name' => ['en' => 'Home'],
        'title' => ['en' => 'Home'],
    ]);
}

/** @return array{0: Page, 1: Page} [category, article] */
function breadcrumbsBlog(PageStatus $categoryStatus): array
{
    $category = Page::factory()->category()->create([
        'status' => $categoryStatus,
        'name' => ['en' => 'Blog'],
        'title' => ['en' => 'Blog'],
    ]);
    $article = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $category->id,
        'name' => ['en' => 'First Post'],
        'title' => ['en' => 'First Post'],
    ]);

    return [$category, $article];
}

function breadcrumbsGet(Page $page): TestResponse
{
    return test()->get('/' . $page->pathFor('en'), ['X-Inertia' => 'true'])->assertOk();
}

/** @return list<string> "label url" per crumb */
function breadcrumbsTrail(TestResponse $response): array
{
    return array_map(
        static fn (array $crumb): string => $crumb['label'] . ' ' . $crumb['url'],
        $response->json('props.breadcrumbs'),
    );
}

/** @return list<string>|null the BreadcrumbList item names, null when the object is absent */
function breadcrumbsJsonLd(TestResponse $response): ?array
{
    foreach ($response->json('props.seo.jsonLd') as $object) {
        if (($object['@type'] ?? null) === 'BreadcrumbList') {
            return array_column($object['itemListElement'], 'name');
        }
    }

    return null;
}

it('leaves a draft category out of the trail, BreadcrumbList and {parent} until it is published', function (): void {
    breadcrumbsHome();
    [$category, $article] = breadcrumbsBlog(PageStatus::Draft);

    // The article itself stays on the site — only the crumb goes.
    $response = breadcrumbsGet($article);

    expect(breadcrumbsTrail($response))->toBe(['Home /', 'First Post /blog/first-post'])
        ->and(breadcrumbsJsonLd($response))->toBe(['Home', 'First Post'])
        ->and($response->json('props.seo.title'))->toBe('First Post — Acme Co');

    $category->update(['status' => PageStatus::Published]);
    $response = breadcrumbsGet($article);

    expect(breadcrumbsTrail($response))->toBe(['Home /', 'Blog /blog', 'First Post /blog/first-post'])
        ->and(breadcrumbsJsonLd($response))->toBe(['Home', 'Blog', 'First Post'])
        ->and($response->json('props.seo.title'))->toBe('First Post — Blog — Acme Co');
});

it('leaves an expired site root out of the trail until it is live again', function (): void {
    $home = breadcrumbsHome(live: false);
    [, $article] = breadcrumbsBlog(PageStatus::Published);

    $response = breadcrumbsGet($article);

    expect(breadcrumbsTrail($response))->toBe(['Blog /blog', 'First Post /blog/first-post'])
        ->and(breadcrumbsJsonLd($response))->toBe(['Blog', 'First Post']);

    // Same test, same cached controller and Breadcrumbs: the root's
    // liveness must not come from the first request.
    $home->update(['unpublish_at' => null]);
    $response = breadcrumbsGet($article);

    expect(breadcrumbsTrail($response))->toBe(['Home /', 'Blog /blog', 'First Post /blog/first-post'])
        ->and(breadcrumbsJsonLd($response))->toBe(['Home', 'Blog', 'First Post']);
});

it('gives no trail and no BreadcrumbList when only the page itself survives the filter', function (): void {
    breadcrumbsHome(live: false);
    [, $article] = breadcrumbsBlog(PageStatus::Draft);

    $response = breadcrumbsGet($article);

    expect($response->json('props.breadcrumbs'))->toBe([])
        ->and(breadcrumbsJsonLd($response))->toBeNull();
});

it('keeps a draft page itself at the end of its trail in a signed preview', function (): void {
    breadcrumbsHome();
    [, $article] = breadcrumbsBlog(PageStatus::Published);
    $article->update(['status' => PageStatus::Draft]);

    $response = test()->get(app(PreviewLink::class)->for($article, 'en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.isPreview', true);

    expect(breadcrumbsTrail($response))->toBe(['Home /', 'Blog /blog', 'First Post /blog/first-post'])
        ->and(breadcrumbsJsonLd($response))->toBe(['Home', 'Blog', 'First Post']);
});
