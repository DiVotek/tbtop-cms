<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaSelector;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;
use Tbtop\Cms\Tests\Fixtures\ServicePageType;
use Tbtop\Cms\Tests\Fixtures\ServicesCategoryType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    PageTypeRegistry::extend(fn (): array => [ServicePageType::class, ServicesCategoryType::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    test()->actingAs(PageEditHelpers::admin(), 'web');
});

/** @param  array<string, mixed>  $form */
function createPage(array $form): TestResponse
{
    return test()->postJson('/admin/pages/actions/pageCreateSave', ['payload' => ['form' => ['title' => ['en' => ''], ...$form]]]);
}

function category(?string $subtype, string $slug): Page
{
    return Page::create(['type' => 'category', 'subtype' => $subtype, 'status' => PageStatus::Published, 'name' => ['en' => ucfirst($slug)], 'slug' => ['en' => $slug]]);
}

it('publishes the schema its subtype declares instead of the base type\'s', function (): void {
    $parent = category(null, 'blog');
    $service = Page::create(['type' => 'article', 'subtype' => 'service', 'parent_id' => $parent->id, 'status' => PageStatus::Published, 'name' => ['en' => 'Audit']]);
    $post = Page::create(['type' => 'article', 'parent_id' => $parent->id, 'status' => PageStatus::Published, 'name' => ['en' => 'Post']]);

    $selector = app(SchemaSelector::class);

    expect($selector->selectFor($service)->key())->toBe('ItemPage')
        ->and($selector->selectFor($post)->key())->toBe('Article');
});

it('creates an article with the subtype picked in the create form', function (): void {
    $parent = category(null, 'blog');

    createPage(['name' => ['en' => 'Audit'], 'slug' => ['en' => 'audit'], 'type' => 'article', 'parent_id' => $parent->id, 'subtype_article' => 'service'])->assertOk();

    expect(Page::query()->firstWhere('type', 'article')->subtype)->toBe('service');
});

it('gives a new article without a subtype the one its category declares, and none under a plain category', function (): void {
    $services = category('services', 'services');
    $blog = category(null, 'blog');

    createPage(['name' => ['en' => 'Audit'], 'slug' => ['en' => 'audit'], 'type' => 'article', 'parent_id' => $services->id, 'subtype_article' => ''])->assertOk();
    createPage(['name' => ['en' => 'Post'], 'slug' => ['en' => 'post'], 'type' => 'article', 'parent_id' => $blog->id, 'subtype_article' => ''])->assertOk();

    expect(Page::query()->where('type', 'article')->orderBy('id')->pluck('subtype')->all())->toBe(['service', null]);
});

it('gives the default subtype to a page that becomes an article in the category, not to an article already there', function (): void {
    $services = category('services', 'services');
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Audit']]);
    $article = Page::create(['type' => 'article', 'parent_id' => $services->id, 'status' => PageStatus::Published, 'name' => ['en' => 'Plain']]);

    $save = fn (Page $p, array $form) => test()->post("/admin/pages/{$p->id}/edit/forms/pageEditor", [...PageEditHelpers::baseEditPayload(), ...$form]);
    $save($page, ['type' => 'page', 'parent_id' => $services->id])->assertSessionHasNoErrors();
    $save($article, ['type' => 'article', 'parent_id' => $services->id])->assertSessionHasNoErrors();

    expect($page->refresh()->only(['type', 'subtype']))->toBe(['type' => 'article', 'subtype' => 'service'])
        ->and($article->refresh()->subtype)->toBeNull();
});
