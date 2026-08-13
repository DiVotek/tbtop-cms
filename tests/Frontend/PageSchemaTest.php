<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Frontend\Seo\PageSchema;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageUrl;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

/** @param array<string, mixed> $overrides */
function schemaInput(array $overrides = []): array
{
    return [
        'name' => 'About us',
        'description' => 'What we do.',
        'url' => 'https://example.com/about-us',
        'image' => null,
        'profileImage' => null,
        ...$overrides,
    ];
}

it('publishes a plain WebPage when no subtype was chosen', function (): void {
    $page = Page::factory()->create(['type' => 'page', 'seo' => []]);

    $object = (new PageSchema(app(PageUrl::class)))->build($page, 'en', schemaInput());

    expect($object['@type'])->toBe('WebPage')
        ->and($object['name'])->toBe('About us')
        ->and($object['url'])->toBe('https://example.com/about-us');
});

it('falls back to WebPage when a ProfilePage has no name to describe', function (): void {
    // A ProfilePage without mainEntity is invalid structured data — Search
    // Console reports it as a missing required field, so an unfilled profile
    // must degrade rather than publish a broken type.
    $page = Page::factory()->create([
        'type' => 'page',
        'seo' => ['schema' => ['type' => 'ProfilePage', 'profile_name' => ['en' => '']]],
    ]);

    $object = (new PageSchema(app(PageUrl::class)))->build($page, 'en', schemaInput());

    expect($object['@type'])->toBe('WebPage')
        ->and($object)->not->toHaveKey('mainEntity');
});

it('builds a ProfilePage whose entity falls back to the page description and image', function (): void {
    $page = Page::factory()->create([
        'type' => 'page',
        'seo' => ['schema' => [
            'type' => 'ProfilePage',
            'profile_name' => ['en' => 'Jane Doe'],
            'profile_alternate_name' => ['en' => 'jdoe'],
        ]],
    ]);

    $object = (new PageSchema(app(PageUrl::class)))->build($page, 'en', schemaInput([
        'image' => 'https://example.com/page.png',
    ]));

    expect($object['@type'])->toBe('ProfilePage')
        ->and($object['mainEntity'])->toBe([
            '@type' => 'Person',
            'name' => 'Jane Doe',
            'alternateName' => 'jdoe',
            'description' => 'What we do.',
            'image' => 'https://example.com/page.png',
        ]);
});

it('prefers the profile photo over the page image for the entity', function (): void {
    $page = Page::factory()->create([
        'type' => 'page',
        'seo' => ['schema' => ['type' => 'ProfilePage', 'profile_name' => ['en' => 'Jane Doe']]],
    ]);

    $object = (new PageSchema(app(PageUrl::class)))->build($page, 'en', schemaInput([
        'image' => 'https://example.com/page.png',
        'profileImage' => 'https://example.com/jane.png',
    ]));

    expect($object['mainEntity']['image'])->toBe('https://example.com/jane.png')
        // The page's own image still describes the page itself.
        ->and($object['image'])->toBe('https://example.com/page.png');
});

it('drops profile links that are not absolute http urls', function (): void {
    $page = Page::factory()->create([
        'type' => 'page',
        'seo' => ['schema' => [
            'type' => 'ProfilePage',
            'profile_name' => ['en' => 'Jane Doe'],
            'profile_same_as' => ['https://example.com/jane', 'not a url', 'javascript:alert(1)'],
        ]],
    ]);

    $object = (new PageSchema(app(PageUrl::class)))->build($page, 'en', schemaInput());

    expect($object['mainEntity']['sameAs'])->toBe(['https://example.com/jane']);
});

it('publishes an Article with the chosen flavour, its author and its dates', function (): void {
    $blog = Page::factory()->create(['type' => 'category', 'name' => ['en' => 'Blog']]);
    $page = Page::factory()->create([
        'type' => 'article',
        'parent_id' => $blog->getKey(),
        'status' => PageStatus::Published,
        'published_at' => now()->subDay(),
        'seo' => ['schema' => [
            'article_type' => 'NewsArticle',
            'author' => ['en' => 'Dmytro Ivanov'],
        ]],
    ]);

    $object = (new PageSchema(app(PageUrl::class)))->build($page, 'en', schemaInput());

    expect($object['@type'])->toBe('NewsArticle')
        ->and($object['author'])->toBe(['@type' => 'Person', 'name' => 'Dmytro Ivanov'])
        ->and($object['mainEntityOfPage'])->toBe([
            '@type' => 'WebPage',
            '@id' => 'https://example.com/about-us',
        ])
        ->and($object['datePublished'])->toBe($page->published_at->toIso8601String());
});

it('omits datePublished while an article is unpublished', function (): void {
    // Announcing a publication date for something not yet public would be
    // plainly false; Article tolerates the property's absence.
    $blog = Page::factory()->create(['type' => 'category', 'name' => ['en' => 'Blog']]);
    $page = Page::factory()->create([
        'type' => 'article',
        'parent_id' => $blog->getKey(),
        'status' => PageStatus::Draft,
        'published_at' => null,
        'seo' => [],
    ]);

    $object = (new PageSchema(app(PageUrl::class)))->build($page, 'en', schemaInput());

    expect($object['@type'])->toBe('Article')
        ->and($object)->not->toHaveKey('datePublished')
        ->and($object)->not->toHaveKey('author');
});

it('lists only live indexable children in a category hasPart', function (): void {
    $category = Page::factory()->create([
        'type' => 'category',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Blog'],
    ]);

    // title, not name: a child is labelled by the same title→name cascade the
    // breadcrumb trail uses, and the factory fills title with a random string.
    $live = Page::factory()->create([
        'type' => 'article',
        'parent_id' => $category->getKey(),
        'status' => PageStatus::Published,
        'is_indexable' => true,
        'name' => ['en' => 'Live post'],
        'title' => ['en' => 'Live post'],
    ]);

    Page::factory()->create([
        'type' => 'article',
        'parent_id' => $category->getKey(),
        'status' => PageStatus::Draft,
        'is_indexable' => true,
        'name' => ['en' => 'Draft post'],
    ]);

    Page::factory()->create([
        'type' => 'article',
        'parent_id' => $category->getKey(),
        'status' => PageStatus::Published,
        'is_indexable' => false,
        'name' => ['en' => 'Hidden post'],
    ]);

    $object = (new PageSchema(app(PageUrl::class)))->build($category, 'en', schemaInput());

    // Compared against the child's own stored path rather than $live->pathFor()
    // — the slugs relation is not loaded on this instance, so pathFor() here
    // would read an empty collection and assert against the site root.
    expect($object['@type'])->toBe('CollectionPage')
        ->and($object['hasPart'])->toBe([[
            '@type' => 'WebPage',
            'name' => 'Live post',
            'url' => url((string) $live->slugs()->where('locale', 'en')->value('path')),
        ]]);
});

it('omits datePublished for a page unpublished after it had been published', function (): void {
    // Unpublishing keeps the historical timestamp, and admin preview renders
    // pages that are not live — neither may advertise a publication date for
    // content the public cannot reach.
    $blog = Page::factory()->create(['type' => 'category', 'name' => ['en' => 'Blog']]);
    $page = Page::factory()->create([
        'type' => 'article',
        'parent_id' => $blog->getKey(),
        'status' => PageStatus::Draft,
        'published_at' => now()->subMonth(),
    ]);

    $object = (new PageSchema(app(PageUrl::class)))->build($page, 'en', schemaInput());

    expect($page->published_at)->not->toBeNull()
        ->and($object)->not->toHaveKey('datePublished')
        ->and($object)->toHaveKey('dateModified');
});

it('omits datePublished for a page whose unpublish_at has passed', function (): void {
    $blog = Page::factory()->create(['type' => 'category', 'name' => ['en' => 'Blog']]);
    $page = Page::factory()->create([
        'type' => 'article',
        'parent_id' => $blog->getKey(),
        'status' => PageStatus::Published,
        'published_at' => now()->subMonth(),
        'unpublish_at' => now()->subDay(),
    ]);

    $object = (new PageSchema(app(PageUrl::class)))->build($page, 'en', schemaInput());

    expect($object)->not->toHaveKey('datePublished');
});

it('states dates on every static page, not only on profiles', function (): void {
    $page = Page::factory()->create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'published_at' => now()->subDay(),
        'seo' => ['schema' => ['type' => 'ContactPage']],
    ]);

    $object = (new PageSchema(app(PageUrl::class)))->build($page, 'en', schemaInput());

    expect($object['@type'])->toBe('ContactPage')
        ->and($object['datePublished'])->toBe($page->published_at->toIso8601String())
        ->and($object['dateModified'])->toBe($page->updated_at->toIso8601String());
});

it('prefixes hasPart urls with the locale the page is rendered in', function (): void {
    // A bare url($path) would advertise the default-locale address — a 404 for
    // any child that has no default-locale slug. Same rule as canonical.
    Language::factory()->create(['locale' => 'uk', 'is_default' => false]);

    $category = Page::factory()->create([
        'type' => 'category',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Blog', 'uk' => 'Блог'],
    ]);

    Page::factory()->create([
        'type' => 'article',
        'parent_id' => $category->getKey(),
        'status' => PageStatus::Published,
        'is_indexable' => true,
        'name' => ['en' => 'Post', 'uk' => 'Допис'],
        'title' => ['en' => 'Post', 'uk' => 'Допис'],
    ]);

    $object = (new PageSchema(app(PageUrl::class)))->build($category, 'uk', schemaInput());

    expect($object['hasPart'][0]['url'])->toStartWith(url('/uk/'));
});

it('caps hasPart so a large category does not inline its whole subtree', function (): void {
    $category = Page::factory()->create([
        'type' => 'category',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Big'],
    ]);

    Page::factory()->count(105)->create([
        'type' => 'article',
        'parent_id' => $category->getKey(),
        'status' => PageStatus::Published,
        'is_indexable' => true,
    ]);

    $object = (new PageSchema(app(PageUrl::class)))->build($category, 'en', schemaInput());

    expect($object['hasPart'])->toHaveCount(100);
});

it('states dates on a category too', function (): void {
    // All three branches — article, category, page — read the same dates()
    // helper; this pins the one that is easiest to forget.
    $category = Page::factory()->create([
        'type' => 'category',
        'status' => PageStatus::Published,
        'published_at' => now()->subDay(),
        'name' => ['en' => 'Blog'],
    ]);

    $object = (new PageSchema(app(PageUrl::class)))->build($category, 'en', schemaInput());

    expect($object['datePublished'])->toBe($category->published_at->toIso8601String())
        ->and($object)->toHaveKey('dateModified');
});

it('omits hasPart entirely for a category with nothing live under it', function (): void {
    $category = Page::factory()->create([
        'type' => 'category',
        'status' => PageStatus::Published,
        'name' => ['en' => 'Empty'],
    ]);

    $object = (new PageSchema(app(PageUrl::class)))->build($category, 'en', schemaInput());

    expect($object['@type'])->toBe('CollectionPage')
        ->and($object)->not->toHaveKey('hasPart');
});
