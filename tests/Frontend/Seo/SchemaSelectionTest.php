<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Frontend\Seo\SeoResolver;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\NewsPageType;
use Tbtop\Cms\Tests\Fixtures\PlainPageType;
use Tbtop\Cms\Tests\Fixtures\TwoSchemaPageType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

it('renders the stored schema key when the page type offers more than one', function (): void {
    PageTypeRegistry::extend(fn (): array => [TwoSchemaPageType::class]);
    $page = Page::factory()->create([
        'type' => 'two_schema',
        'status' => PageStatus::Published,
        'seo' => ['schema' => ['type' => 'AboutPage']],
    ]);

    $jsonLd = app(SeoResolver::class)->resolve($page, 'en')['jsonLd'];

    expect(collect($jsonLd)->pluck('@type'))->toContain('AboutPage');
});

it('falls back to the first schema key when the stored one has drifted out of range', function (): void {
    PageTypeRegistry::extend(fn (): array => [TwoSchemaPageType::class]);
    $page = Page::factory()->create([
        'type' => 'two_schema',
        'status' => PageStatus::Published,
        'seo' => ['schema' => ['type' => 'ItemPage']],
    ]);

    $jsonLd = app(SeoResolver::class)->resolve($page, 'en')['jsonLd'];

    expect(collect($jsonLd)->pluck('@type'))
        ->toContain('WebPage')
        ->not->toContain('ItemPage');
});

it('derives the Article author and articleAuthor from the site identity settings', function (): void {
    app(Settings::class)->set('seo.identity.type', 'organization');
    app(Settings::class)->set('site.name', 'Acme Inc');
    $blog = Page::factory()->category()->create(['name' => ['en' => 'Blog']]);
    $page = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $blog->getKey(),
    ]);

    $result = app(SeoResolver::class)->resolve($page, 'en');
    $article = collect($result['jsonLd'])->firstWhere('@type', 'Article');

    expect($article['author'])->toBe(['@type' => 'Organization', 'name' => 'Acme Inc'])
        ->and($result['articleAuthor'])->toBe('Acme Inc');
});

it('derives ogType and articlePublishedTime from the schema, not from page->type', function (): void {
    PageTypeRegistry::extend(fn (): array => [NewsPageType::class, PlainPageType::class]);

    $news = Page::factory()->create([
        'type' => 'news',
        'status' => PageStatus::Published,
        'published_at' => now()->subDay(),
    ]);
    $plain = Page::factory()->create(['type' => 'plain', 'status' => PageStatus::Published]);

    $newsResult = app(SeoResolver::class)->resolve($news, 'en');
    $plainResult = app(SeoResolver::class)->resolve($plain, 'en');

    expect($newsResult['ogType'])->toBe('article')
        ->and($newsResult['articlePublishedTime'])->not->toBeNull()
        ->and($plainResult['ogType'])->toBe('website')
        ->and($plainResult['articlePublishedTime'])->toBeNull();
});
