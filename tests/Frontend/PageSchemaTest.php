<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Frontend\Seo\SeoResolver;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

it('publishes a plain WebPage node when no subtype was chosen', function (): void {
    $page = Page::factory()->create(['type' => 'page', 'status' => PageStatus::Published, 'seo' => []]);

    $jsonLd = app(SeoResolver::class)->resolve($page, 'en')['jsonLd'];
    $node = collect($jsonLd)->firstWhere('@type', 'WebPage');

    expect($node)->not->toBeNull();
});

it('publishes datePublished only for a live article', function (): void {
    $blog = Page::factory()->create(['type' => 'category', 'name' => ['en' => 'Blog']]);
    $live = Page::factory()->create([
        'type' => 'article',
        'parent_id' => $blog->getKey(),
        'status' => PageStatus::Published,
        'published_at' => now()->subDay(),
    ]);
    $draft = Page::factory()->create([
        'type' => 'article',
        'parent_id' => $blog->getKey(),
        'status' => PageStatus::Draft,
        'published_at' => null,
    ]);

    $liveArticle = collect(app(SeoResolver::class)->resolve($live, 'en')['jsonLd'])->firstWhere('@type', 'Article');
    $draftArticle = collect(app(SeoResolver::class)->resolve($draft, 'en')['jsonLd'])->firstWhere('@type', 'Article');

    expect($liveArticle['datePublished'])->toBe($live->published_at->toIso8601String())
        ->and($draftArticle)->not->toHaveKey('datePublished');
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

    $jsonLd = app(SeoResolver::class)->resolve($category, 'en')['jsonLd'];
    $node = collect($jsonLd)->firstWhere('@type', 'CollectionPage');

    // Compared against the child's own stored path rather than $live->pathFor()
    // — the slugs relation is not loaded on this instance, so pathFor() here
    // would read an empty collection and assert against the site root.
    expect($node['hasPart'])->toBe([[
        '@type' => 'WebPage',
        'name' => 'Live post',
        'url' => url((string) $live->slugs()->where('locale', 'en')->value('path')),
    ]]);
});

it('cuts an article headline to 110 characters at a word boundary', function (): void {
    $article = Page::factory()->create([
        'type' => 'article',
        'parent_id' => Page::factory()->create(['type' => 'category'])->getKey(),
        'status' => PageStatus::Published,
        'title' => ['en' => str_repeat('abcdefg ', 20)],
    ]);

    $node = collect(app(SeoResolver::class)->resolve($article, 'en')['jsonLd'])->firstWhere('@type', 'Article');

    // 13 whole words fit in 110 characters; the 14th would be cut mid-word.
    expect($node['headline'])->toBe(implode(' ', array_fill(0, 13, 'abcdefg')));
});

it('names the identity as article publisher only when it is an organization', function (string $type, ?array $publisher): void {
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Settings::class)->set('seo.identity.type', $type);
    $article = Page::factory()->create([
        'type' => 'article',
        'parent_id' => Page::factory()->create(['type' => 'category'])->getKey(),
        'status' => PageStatus::Published,
    ]);

    $node = collect(app(SeoResolver::class)->resolve($article, 'en')['jsonLd'])->firstWhere('@type', 'Article');

    expect($node['publisher'] ?? null)->toBe($publisher);
})->with([
    'organization' => ['organization', ['@type' => 'Organization', 'name' => 'Acme Co', 'url' => 'http://localhost']],
    'person' => ['person', null],
]);
