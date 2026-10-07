<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Types\ArticleType;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;
use Tbtop\Cms\Tests\Fixtures\SectionType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    test()->actingAs(PageEditHelpers::admin(), 'web');
});

/** @param  array<string, mixed>  $overrides */
function saveLeaf(Page $page, array $overrides): TestResponse
{
    return test()->post("/admin/pages/{$page->id}/edit/forms/pageEditor", [...PageEditHelpers::baseEditPayload(), ...$overrides]);
}

/** @param  array<string, mixed>  $form */
function createLeaf(array $form): TestResponse
{
    return test()->postJson('/admin/pages/actions/pageCreateSave', ['payload' => ['form' => [
        'title' => ['en' => ''],
        ...$form,
    ]]]);
}

function blog(): Page
{
    return Page::create(['type' => 'category', 'status' => PageStatus::Published, 'name' => ['en' => 'Blog'], 'slug' => ['en' => 'blog']]);
}

it('turns a page given a category into an article under it', function (): void {
    $blog = blog();
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'News'], 'slug' => ['en' => 'news']]);

    saveLeaf($page, ['type' => 'page', 'parent_id' => $blog->id, 'slug' => ['en' => 'news']])->assertSessionHasNoErrors();

    expect($page->refresh()->only(['type', 'parent_id']))->toBe(['type' => 'article', 'parent_id' => $blog->id])
        ->and($page->pathFor('en'))->toBe('blog/news');
});

it('keeps a page under a host container a page', function (): void {
    PageTypeRegistry::extend(fn (): array => [SectionType::class]);
    $section = Page::create(['type' => 'section', 'status' => PageStatus::Published, 'name' => ['en' => 'Work'], 'slug' => ['en' => 'work']]);
    $page = Page::create(['type' => 'page', 'parent_id' => $section->id, 'status' => PageStatus::Published, 'name' => ['en' => 'Team'], 'slug' => ['en' => 'team']]);

    saveLeaf($page, ['type' => 'page', 'parent_id' => $section->id, 'slug' => ['en' => 'team']])->assertSessionHasNoErrors();

    expect($page->refresh()->only(['type', 'parent_id']))->toBe(['type' => 'page', 'parent_id' => $section->id]);
});

it('turns an article whose category is removed into a page at the root', function (): void {
    $article = Page::create(['type' => 'article', 'parent_id' => blog()->id, 'status' => PageStatus::Published, 'name' => ['en' => 'Post'], 'slug' => ['en' => 'post']]);

    saveLeaf($article, ['type' => 'article', 'parent_id' => '', 'slug' => ['en' => 'post']])->assertSessionHasNoErrors();

    expect($article->refresh()->only(['type', 'parent_id']))->toBe(['type' => 'page', 'parent_id' => null])
        ->and($article->pathFor('en'))->toBe('post');
});

it('takes the category away when an article is switched to a page by hand', function (): void {
    $blog = blog();
    $article = Page::create(['type' => 'article', 'parent_id' => $blog->id, 'status' => PageStatus::Published, 'name' => ['en' => 'Post']]);

    saveLeaf($article, ['type' => 'page', 'parent_id' => $blog->id])->assertSessionHasNoErrors();

    expect($article->refresh()->only(['type', 'parent_id']))->toBe(['type' => 'page', 'parent_id' => null]);
});

it('asks for a category when a page is switched to an article without one', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'News']]);

    saveLeaf($page, ['type' => 'article', 'parent_id' => ''])->assertSessionHasErrors('parent_id');

    expect($page->refresh()->type)->toBe('page');
});

it('creates an article only with a category, and a page at the root even with a stale category pick', function (): void {
    $blog = blog();

    createLeaf(['name' => ['en' => 'Orphan'], 'slug' => ['en' => 'orphan'], 'type' => 'article', 'parent_id' => ''])->assertJsonValidationErrors('parent_id');
    createLeaf(['name' => ['en' => 'Post'], 'slug' => ['en' => 'post'], 'type' => 'article', 'parent_id' => $blog->id])->assertOk();
    createLeaf(['name' => ['en' => 'About'], 'slug' => ['en' => 'about'], 'type' => 'page', 'parent_id' => $blog->id])->assertOk();

    expect(Page::query()->where('type', 'article')->pluck('parent_id')->all())->toBe([$blog->id])
        ->and(Page::query()->where('type', 'page')->pluck('parent_id')->all())->toBe([null]);
});

/** A host's article type that may stand at the root (the documented ArticleType override). */
class RootArticleType extends ArticleType
{
    #[Override]
    public function requiresParent(): bool
    {
        return false;
    }
}

it('keeps a root article an article when the host article type does not require a category', function (): void {
    app()->bind(ArticleType::class, RootArticleType::class);
    app()->forgetInstance(PageTypeRegistry::class);
    $article = Page::create(['type' => 'article', 'status' => PageStatus::Published, 'name' => ['en' => 'Note'], 'slug' => ['en' => 'note']]);

    saveLeaf($article, ['type' => 'article', 'parent_id' => '', 'slug' => ['en' => 'note']])->assertSessionHasNoErrors();
    createLeaf(['name' => ['en' => 'Memo'], 'slug' => ['en' => 'memo'], 'type' => 'article', 'parent_id' => ''])->assertOk();

    expect(Page::query()->orderBy('id')->get(['type', 'parent_id'])->toArray())->toBe([
        ['type' => 'article', 'parent_id' => null],
        ['type' => 'article', 'parent_id' => null],
    ]);
});

it('lists pages and articles by their type', function (): void {
    $blog = blog();
    Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'About']]);
    Page::create(['type' => 'article', 'parent_id' => $blog->id, 'status' => PageStatus::Draft, 'name' => ['en' => 'Post']]);
    Page::create(['type' => 'category', 'parent_id' => $blog->id, 'status' => PageStatus::Draft, 'name' => ['en' => 'Tips']]);

    $names = fn (string $tab): array => array_column(test()->getJson("/admin/pages/tables/pages?tab={$tab}")->assertOk()->json('data.data'), 'name');

    expect($names('pages'))->toBe(['About'])
        ->and($names('articles'))->toBe(['Post']);
});

it('confirms a save that changes a page into an article and back, and never for a category', function (): void {
    $blog = blog();
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'About']]);
    $article = Page::create(['type' => 'article', 'parent_id' => $blog->id, 'status' => PageStatus::Draft, 'name' => ['en' => 'Post']]);

    $flip = function (Page $p): ?array {
        $actions = [];
        collectNodesByKind(test()->get("/admin/pages/{$p->id}/edit", ['X-Inertia' => 'true'])->assertOk()->json('props.structure'), 'action', $actions);

        return collect($actions)->firstWhere('name', 'saveLeafFlip');
    };

    expect($flip($page)['options']['confirm']['title'] ?? null)->toBe('Turn this page into an article?')
        ->and($flip($article)['options']['confirm']['title'] ?? null)->toBe('Turn this article into a page?')
        ->and($flip($blog))->toBeNull();
});
