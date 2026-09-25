<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Types\ArticleType;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;

uses(RefreshDatabase::class);

/** A host's article type that also lets an article be published as a plain WebPage. */
class HostArticleType extends ArticleType
{
    /** @return non-empty-list<string> */
    #[Override]
    public function schemaTypes(): array
    {
        return [...parent::schemaTypes(), 'WebPage'];
    }
}

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    // Bound before anything resolves the registry, as a host does in register().
    app()->bind(ArticleType::class, HostArticleType::class);
    app()->forgetInstance(PageTypeRegistry::class);

    app(Cms::class)->frontendRoutes();
});

it('lets a container-bound ArticleType subclass drive the article schema choice', function (): void {
    $blog = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Blog']]);
    $article = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $blog->getKey(),
        'name' => ['en' => 'Service'],
    ]);

    $selects = [];
    collectNodesByKind(PageEditHelpers::structure($article->id)['props']['structure'], 'select', $selects);
    $schemaSelect = collect($selects)->firstWhere('name', 'schema_type');

    expect($schemaSelect)->not->toBeNull()
        ->and(array_column($schemaSelect['options']['options'], 'value'))->toContain('WebPage');

    $article->update(['seo' => ['schema' => ['type' => 'WebPage']]]);

    $types = collect(test()->get('/' . $article->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()->json('props.seo.jsonLd'))->pluck('@type')->all();

    expect($types)->toContain('WebPage')->not->toContain('Article');
});
