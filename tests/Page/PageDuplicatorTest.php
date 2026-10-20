<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageRevision;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Page\Support\PageDuplicator;
use Tbtop\Cms\Tests\Fixtures\SourcedType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk', 'is_frontend_active' => true, 'is_admin_active' => true]);
});

it('copies type, parent, translatables, content, seo, image, and is_indexable', function (): void {
    $category = Page::factory()->category()->create(['name' => ['en' => 'Blog', 'uk' => 'Блог']]);
    $source = Page::factory()->article()->create([
        'parent_id' => $category->id,
        'name' => ['en' => 'Post', 'uk' => 'Стаття'],
        'title' => ['en' => 'Post Title', 'uk' => 'Заголовок'],
        'heading' => ['en' => 'Post Heading', 'uk' => ''],
        'summary' => ['en' => 'Summary', 'uk' => ''],
        'body' => ['en' => 'Body', 'uk' => ''],
        'content' => [['id' => 'b1', 'kind' => 'Hero', 'data' => ['title' => 'Hi']]],
        'seo' => ['title' => 'SEO title', 'description' => 'SEO desc', 'focus_keyword' => '', 'og_title' => '', 'og_description' => '', 'og_image' => ''],
        'image' => 'media-123',
        'is_indexable' => false,
    ]);

    $copy = (new PageDuplicator)->duplicate($source);

    expect($copy->type)->toBe('article')
        ->and($copy->parent_id)->toBe($category->id)
        ->and($copy->getTranslation('title', 'en', false))->toBe('Post Title')
        ->and($copy->getTranslation('title', 'uk', false))->toBe('Заголовок')
        ->and($copy->getTranslation('heading', 'en', false))->toBe('Post Heading')
        ->and($copy->getTranslation('summary', 'en', false))->toBe('Summary')
        ->and($copy->getTranslation('body', 'en', false))->toBe('Body')
        ->and($copy->content)->toBe([['id' => 'b1', 'kind' => 'Hero', 'data' => ['title' => 'Hi']]])
        ->and($copy->seo['title'])->toBe('SEO title')
        ->and($copy->seo['description'])->toBe('SEO desc')
        ->and($copy->image)->toBe('media-123')
        ->and($copy->is_indexable)->toBeFalse();
});

it('resets status to draft and clears the publish schedule regardless of the source', function (): void {
    $source = Page::factory()->create([
        'status' => PageStatus::Published,
        'published_at' => now()->subDay(),
        'unpublish_at' => now()->addWeek(),
    ]);

    $copy = (new PageDuplicator)->duplicate($source);

    expect($copy->status)->toBe(PageStatus::Draft)
        ->and($copy->published_at)->toBeNull()
        ->and($copy->unpublish_at)->toBeNull();
});

it('turns a system-page source into an ordinary (non-system) copy', function (): void {
    $source = Page::factory()->create(['is_system' => true, 'name' => ['en' => 'Home']]);

    $copy = (new PageDuplicator)->duplicate($source);

    expect($copy->is_system)->toBeFalse();
});

it('places the copy at the end of its siblings by sort_order', function (): void {
    $category = Page::factory()->category()->create();
    Page::factory()->article()->create(['parent_id' => $category->id, 'sort_order' => 0]);
    $second = Page::factory()->article()->create(['parent_id' => $category->id, 'sort_order' => 5]);

    $copy = (new PageDuplicator)->duplicate($second);

    expect($copy->sort_order)->toBe(6);
});

it('starts sort_order at 0 for the first child of an empty parent', function (): void {
    $category = Page::factory()->category()->create();
    $source = Page::factory()->article()->create(['parent_id' => $category->id, 'sort_order' => 0]);

    $copy = (new PageDuplicator)->duplicate($source);

    expect($copy->sort_order)->toBe(1);
});

it('appends a per-locale "(copy)" suffix to the name, preserving empty locales as-is', function (): void {
    $source = Page::factory()->create(['name' => ['en' => 'About', 'uk' => '']]);

    $copy = (new PageDuplicator)->duplicate($source);

    expect($copy->getTranslation('name', 'en', false))->toBe('About (copy)')
        ->and($copy->getTranslation('name', 'uk', false))->toBe('');
});

it('does not copy children (shallow copy only)', function (): void {
    $category = Page::factory()->category()->create();
    Page::factory()->article()->create(['parent_id' => $category->id]);

    $copy = (new PageDuplicator)->duplicate($category);

    expect(Page::query()->where('parent_id', $copy->id)->count())->toBe(0);
});

it('writes a fresh create revision snapshot for the copy', function (): void {
    $source = Page::factory()->create(['name' => ['en' => 'About']]);

    $copy = (new PageDuplicator)->duplicate($source);

    $revision = PageRevision::query()->where('page_id', $copy->id)->where('kind', 'create')->first();
    expect($revision)->not->toBeNull();
});

it('gives the copy unique slugs that differ from the source\'s', function (): void {
    $source = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $copy = (new PageDuplicator)->duplicate($source);

    $sourcePath = $source->fresh()->pathFor('en');
    $copyPath = $copy->fresh()->pathFor('en');

    expect($copyPath)->not->toBeNull()
        ->and($copyPath)->not->toBe($sourcePath)
        ->and(PageSlug::query()->where('locale', 'en')->where('path', $copyPath)->count())->toBe(1);
});

it('produces two distinct, uniquely-slugged copies when duplicated twice', function (): void {
    $source = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $first = (new PageDuplicator)->duplicate($source);
    $second = (new PageDuplicator)->duplicate($source);

    expect($first->id)->not->toBe($second->id)
        ->and($first->fresh()->pathFor('en'))->not->toBe($second->fresh()->pathFor('en'));
});

it('raises PageSaved with wasCreated=true for the copy', function (): void {
    $source = Page::factory()->create(['name' => ['en' => 'About']]);
    Event::fake([PageSaved::class]);

    $copy = (new PageDuplicator)->duplicate($source);

    Event::assertDispatched(PageSaved::class, fn (PageSaved $e): bool => $e->page->is($copy) && $e->wasCreated === true);
});

it('starts the copy at the default lock_version rather than the source\'s', function (): void {
    $source = Page::factory()->create(['name' => ['en' => 'About']]);
    Page::query()->whereKey($source->id)->toBase()->update(['lock_version' => 5]);

    $copy = (new PageDuplicator)->duplicate($source->fresh());

    expect($copy->fresh()->lock_version)->toBe(0);
});

it('refuses to duplicate a page of a source-backed type', function (): void {
    config()->set('tbtop-cms.pages.types', [SourcedType::class]);
    $source = Page::create(['type' => 'sourced', 'source_id' => 'thing-1', 'name' => ['en' => 'Thing']]);

    expect(fn () => (new PageDuplicator)->duplicate($source))
        ->toThrow(DomainException::class, 'Pages of type sourced are created by their source, not by hand.')
        ->and(Page::query()->count())->toBe(1);
});
