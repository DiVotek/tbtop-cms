<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\StarterBlockSync;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $admin = AdminUser::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'secret',
    ]);
    CmsUserProfile::query()->forceCreate(['user_id' => $admin->id]);
    test()->actingAs($admin, 'web');
});

it('labels an apply-to-existing run as one batch of starter_blocks_applied entries', function (): void {
    $category = Page::factory()->category()->create([
        'name' => 'Guides',
        'starter_blocks' => ['article' => [['id' => 'tpl-1', 'kind' => 'Hero']]],
    ]);
    $article = Page::factory()->article()->create([
        'parent_id' => $category->id,
        'content' => [],
    ]);
    Activity::query()->delete();

    (new StarterBlockSync)->apply($category, 'article');

    $entries = Activity::query()->get();

    $batchIds = $entries->map(fn (Activity $activity): mixed => data_get($activity->properties, 'batch'));

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('event')->unique()->all())->toBe(['starter_blocks_applied'])
        ->and($batchIds->unique())->toHaveCount(1)
        ->and($batchIds->first())->not->toBeNull();

    $categoryEntry = $entries->firstWhere('subject_id', $category->id);
    $pageEntry = $entries->firstWhere('subject_id', $article->id);

    expect($categoryEntry)->not->toBeNull()
        ->and(data_get($categoryEntry->properties, 'subject_label'))->toBe('Guides')
        ->and(data_get($categoryEntry->properties, 'context.affected_pages'))->toBe(1)
        ->and(data_get($categoryEntry->properties, 'context.starter_type'))->toBe('article')
        ->and($pageEntry)->not->toBeNull()
        ->and(data_get($pageEntry->properties, 'context.category_id'))->toBe($category->id);
});

it('does not log an apply-to-existing run outside an admin session', function (): void {
    $category = Page::factory()->category()->create([
        'starter_blocks' => ['article' => [['id' => 'tpl-1', 'kind' => 'Hero']]],
    ]);
    Page::factory()->article()->create(['parent_id' => $category->id, 'content' => []]);
    Activity::query()->delete();
    auth()->guard('web')->logout();

    (new StarterBlockSync)->apply($category, 'article');

    expect(Activity::query()->count())->toBe(0);
});
