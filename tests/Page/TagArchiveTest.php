<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;
use Tbtop\Cms\Tests\Fixtures\TaggedPostPageType;
use Tbtop\Cms\Tests\Fixtures\TagPageType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    PageTypeRegistry::extend(fn (): array => [TagPageType::class, TaggedPostPageType::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);
    app(Cms::class)->frontendRoutes();
});

function tagsHome(): Page
{
    return Page::query()->where('type', 'category')->whereNull('subtype')->where('name->en', 'Tags')->first()
        ?? Page::create(['type' => 'category', 'status' => PageStatus::Published, 'name' => ['en' => 'Tags', 'uk' => 'Теги']]);
}

function blogHome(): Page
{
    return Page::query()->where('type', 'category')->whereNull('subtype')->where('name->en', 'Blog')->first()
        ?? Page::create(['type' => 'category', 'status' => PageStatus::Published, 'name' => ['en' => 'Blog', 'uk' => 'Блог']]);
}

function tag(string $name): Page
{
    return Page::create([
        'type' => 'category', 'subtype' => 'tag', 'parent_id' => tagsHome()->id,
        'status' => PageStatus::Published, 'name' => ['en' => $name, 'uk' => "{$name} uk"],
    ]);
}

/**
 * @param  list<Page>  $tags
 * @param  array<string, mixed>  $attributes
 */
function taggedPost(string $name, array $tags, array $attributes = []): Page
{
    return Page::create([
        'type' => 'article', 'subtype' => 'tagged_post', 'parent_id' => blogHome()->id,
        'status' => PageStatus::Published, 'name' => ['en' => $name],
        'fields' => ['tags' => array_map(static fn (Page $tag): string => (string) $tag->id, $tags)],
        ...$attributes,
    ]);
}

/** @return array<string, mixed> */
function tagProps(Page $tag, string $query = ''): array
{
    return test()->get('/' . $tag->refresh()->pathFor('en') . $query, ['X-Inertia' => 'true'])->assertOk()->json('props');
}

it('lists the live articles of a tag newest first, a page at a time, each page its own canonical, whatever else the page lists', function (): void {
    $japan = tag('Japan');
    taggedPost('Ramen', [$japan], ['published_at' => Carbon::parse('2026-10-01')]);
    taggedPost('Sushi', [$japan], ['published_at' => Carbon::parse('2026-10-03')]);
    taggedPost('Onsen', [$japan], ['published_at' => Carbon::parse('2026-10-02')]);
    taggedPost('Draft', [$japan], ['status' => PageStatus::Draft]);
    taggedPost('Lisbon', [tag('Portugal')]);

    $first = tagProps($japan);
    $second = tagProps($japan, '?page=2');

    expect(array_column($first['page']['fields']['items'], 'name'))->toBe(['Sushi', 'Onsen'])
        ->and($first['page']['fields']['pagination'])->toBe(['page' => 1, 'pages' => 2, 'total' => 3, 'perPage' => 2])
        ->and(array_column($first['page']['fields']['siblings']['items'], 'name'))->toContain('Japan')
        ->and($first['seo']['canonical'])->toBe(url('/tags/japan'))
        ->and(array_column($second['page']['fields']['items'], 'name'))->toBe(['Ramen'])
        ->and($second['page']['fields']['pagination']['page'])->toBe(2)
        ->and($second['seo']['canonical'])->toBe(url('/tags/japan') . '?page=2');
});

it('answers 404 past the last page and ignores a malformed page number', function (): void {
    $japan = tag('Japan');
    taggedPost('Ramen', [$japan]);

    test()->get('/tags/japan?page=2', ['X-Inertia' => 'true'])->assertNotFound();
    expect(tagProps($japan, '?page=abc')['page']['fields']['pagination']['page'])->toBe(1);
});

it('keeps a tag nothing live is tagged with out of the index and the sitemap', function (): void {
    $japan = tag('Japan');
    $empty = tag('Empty');
    taggedPost('Ramen', [$japan]);
    taggedPost('Hidden', [$empty], ['status' => PageStatus::Draft]);

    $sitemap = test()->get('/sitemap-1.xml')->assertOk()->getContent();

    expect(tagProps($empty)['seo']['noindex'])->toBeTrue()
        ->and(tagProps($japan)['seo']['noindex'])->toBeFalse()
        ->and($sitemap)->toContain(url('/tags/japan'))
        ->and($sitemap)->not->toContain(url('/tags/empty'))
        ->and($sitemap)->toContain(url('/blog/ramen'));

    test()->get('/tags/empty', ['X-Inertia' => 'true'])->assertHeader('X-Robots-Tag', 'noindex');
});

it('creates a published tag from the select, next to the other tags', function (): void {
    tag('Japan');
    $post = taggedPost('Ramen', []);

    test()->actingAs(PageEditHelpers::admin(), 'web');
    $created = test()->postJson("/admin/pages/{$post->id}/edit/select-create/field_tagged_post__tags", ['name' => 'Street food'])
        ->assertOk()->json();

    $tag = Page::query()->findOrFail((int) $created['value']);

    expect($created['label'])->toBe('Street food')
        ->and($tag->type)->toBe('category')
        ->and($tag->subtype)->toBe('tag')
        ->and($tag->parent_id)->toBe(tagsHome()->id)
        ->and($tag->status)->toBe(PageStatus::Published)
        ->and($tag->pathFor('en'))->toBe('tags/street-food');
});

it('refuses to create the first tag from the select: the first one shows where tags live', function (): void {
    $post = taggedPost('Ramen', []);

    test()->actingAs(PageEditHelpers::admin(), 'web');
    test()->postJson("/admin/pages/{$post->id}/edit/select-create/field_tagged_post__tags", ['name' => 'Street food'])
        ->assertUnprocessable()->assertJsonValidationErrors('name');

    expect(Page::query()->where('subtype', 'tag')->exists())->toBeFalse();
});

it('keeps tags off the Pages "All" and Category tabs and on a tab of their own', function (): void {
    $japan = tag('Japan');
    $post = taggedPost('Ramen', [$japan]);

    test()->actingAs(PageEditHelpers::admin(), 'web');
    $rows = fn (string $tab): array => array_column(test()->getJson("/admin/pages/tables/pages?tab={$tab}")->assertOk()->json('data.data'), 'id');

    expect($rows('all'))->toContain($post->id)->not->toContain($japan->id)
        ->and($rows('category'))->not->toContain($japan->id)->toContain(tagsHome()->id)
        ->and($rows('tag'))->toBe([$japan->id]);
});

it('pages the children of a category the same way', function (): void {
    $blog = blogHome();
    taggedPost('One', [], ['published_at' => Carbon::parse('2026-10-01')]);
    taggedPost('Two', [], ['published_at' => Carbon::parse('2026-10-02')]);
    taggedPost('Three', [], ['published_at' => Carbon::parse('2026-10-03')]);

    $page = app(Cms::class)->childrenLinksPaged($blog, 'article', perPage: 2, pageNumber: 2, locale: 'en');

    expect(array_column($page['items'], 'name'))->toBe(['One'])
        ->and($page['pagination'])->toBe(['page' => 2, 'pages' => 2, 'total' => 3, 'perPage' => 2]);
});
