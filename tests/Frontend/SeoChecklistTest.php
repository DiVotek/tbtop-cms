<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
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
