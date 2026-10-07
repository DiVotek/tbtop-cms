<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\LegalServicePageType;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;
use Tbtop\Cms\Tests\Fixtures\ReviewerPageType;
use Tbtop\Cms\Tests\Fixtures\SpecialistPageType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    PageTypeRegistry::extend(fn (): array => [SpecialistPageType::class, ReviewerPageType::class, LegalServicePageType::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);
    app(Cms::class)->frontendRoutes();
});

/** @param  array<string, mixed>  $attributes */
function refsArticle(string $subtype, string $name, array $attributes = []): Page
{
    $category = Page::query()->where('type', 'category')->first()
        ?? Page::create(['type' => 'category', 'status' => PageStatus::Published, 'name' => ['en' => 'Team', 'uk' => 'Команда']]);

    return Page::create([
        'type' => 'article', 'subtype' => $subtype, 'parent_id' => $category->id,
        'status' => PageStatus::Published, 'name' => ['en' => $name, 'uk' => "{$name} uk"],
        ...$attributes,
    ]);
}

/** @return array<string, mixed> */
function refsFields(Page $page, string $locale = 'en'): array
{
    $path = ($locale === 'en' ? '/' : "/{$locale}/") . $page->refresh()->pathFor($locale);

    return test()->get($path, ['X-Inertia' => 'true'])->assertOk()->json('props.page.fields');
}

/** @return list<string> */
function refsNames(mixed $list): array
{
    return array_column(is_array($list) ? $list : [], 'name');
}

it('resolves a pages field to its live pages in stored order, with name and url per locale', function (): void {
    $first = refsArticle('legal_service', 'Divorce');
    $draft = refsArticle('legal_service', 'Draft', ['status' => PageStatus::Draft]);
    $deleted = refsArticle('legal_service', 'Gone');
    $second = refsArticle('legal_service', 'Contracts');
    $deleted->delete();

    $specialist = refsArticle('specialist', 'Anna', ['fields' => ['services' => [
        (string) $second->id, (string) $draft->id, (string) $deleted->id, '999999', (string) $first->id,
    ]]]);

    expect(refsFields($specialist)['services'])->toBe([
        ['id' => (string) $second->id, 'name' => 'Contracts', 'url' => '/team/contracts'],
        ['id' => (string) $first->id, 'name' => 'Divorce', 'url' => '/team/divorce'],
    ])
        ->and(refsFields($specialist, 'uk')['services'])->toBe([
            ['id' => (string) $second->id, 'name' => 'Contracts uk', 'url' => '/uk/komanda/contracts-uk'],
            ['id' => (string) $first->id, 'name' => 'Divorce uk', 'url' => '/uk/komanda/divorce-uk'],
        ]);
});

it('rejects on save an id outside the field\'s of() filter, and accepts a draft that matches it', function (): void {
    $specialist = refsArticle('specialist', 'Anna');
    $plain = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $draftService = refsArticle('legal_service', 'Divorce', ['status' => PageStatus::Draft]);

    test()->actingAs(PageEditHelpers::admin(), 'web');
    $save = fn (array $ids) => test()->post("/admin/pages/{$specialist->id}/edit/forms/pageEditor", [
        ...PageEditHelpers::baseEditPayload(),
        'name' => ['en' => 'Anna'],
        'slug' => ['en' => 'anna'],
        'type' => 'article',
        'subtype' => 'specialist',
        'parent_id' => (string) $specialist->parent_id,
        'field_specialist__services' => $ids,
    ]);

    $save([(string) $draftService->id, (string) $plain->id])->assertSessionHasErrors('field_specialist__services.1');
    expect($specialist->refresh()->fields['services'] ?? null)->toBeNull();

    $save([(string) $draftService->id])->assertSessionHasNoErrors();
    expect($specialist->refresh()->fields['services'])->toBe([(string) $draftService->id]);
});

it('accepts on save a reference to a trashed page that matches of(), and still rejects a gone id or a trashed mismatch', function (): void {
    $service = refsArticle('legal_service', 'Divorce');
    $specialist = refsArticle('specialist', 'Anna', ['fields' => ['services' => [(string) $service->id]]]);
    $plain = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $service->delete();
    $plain->delete();

    test()->actingAs(PageEditHelpers::admin(), 'web');
    $save = fn (array $ids) => test()->post("/admin/pages/{$specialist->id}/edit/forms/pageEditor", [
        ...PageEditHelpers::baseEditPayload(),
        'name' => ['en' => 'Anna'],
        'slug' => ['en' => 'anna'],
        'type' => 'article',
        'subtype' => 'specialist',
        'parent_id' => (string) $specialist->parent_id,
        'field_services' => $ids,
    ]);

    $save([(string) $service->id, '999999', (string) $plain->id])
        ->assertSessionHasErrors(['field_services.1', 'field_services.2'])
        ->assertSessionDoesntHaveErrors('field_services.0');

    $save([(string) $service->id])->assertSessionHasNoErrors();
    expect($specialist->refresh()->fields['services'])->toBe([(string) $service->id]);
});

it('offers matching pages in the admin search, drafts included, the edited page left out', function (): void {
    $specialist = refsArticle('specialist', 'Anna');
    $live = refsArticle('legal_service', 'Divorce');
    $draft = refsArticle('legal_service', 'Diversion', ['status' => PageStatus::Draft]);
    $reviewer = refsArticle('reviewer', 'Divan');
    refsArticle('legal_service', 'Contracts');

    test()->actingAs(PageEditHelpers::admin(), 'web');
    $search = fn (Page $page): array => test()
        ->postJson("/admin/pages/{$page->id}/edit/select-options/field_{$page->subtype}__services", ['search' => 'Div'])
        ->assertOk()
        ->json('options');

    expect($search($specialist))->toBe([
        ['value' => (string) $live->id, 'label' => 'Divorce'],
        ['value' => (string) $draft->id, 'label' => 'Diversion'],
    ])
        ->and(array_column($search($reviewer), 'label'))->toBe(['Divorce', 'Diversion']);
});

it('rewrites the reverse index on save: a removed reference leaves the target\'s list', function (): void {
    $service = refsArticle('legal_service', 'Divorce');
    $specialist = refsArticle('specialist', 'Anna', ['fields' => ['services' => [(string) $service->id]]]);

    expect(refsNames(refsFields($service)['specialists']))->toBe(['Anna']);

    // A fresh instance: wasRecentlyCreated stays true on the one create() returned.
    Page::query()->findOrFail($specialist->id)->update(['fields' => ['services' => []]]);

    expect(refsFields($service)['specialists'])->toBe([]);
});

it('lists back references from the declared source subtype only, live only, by sort order', function (): void {
    $service = refsArticle('legal_service', 'Divorce');
    $refs = ['fields' => ['services' => [(string) $service->id]]];

    refsArticle('specialist', 'Zoe', [...$refs, 'sort_order' => 1]);
    refsArticle('specialist', 'Yan', [...$refs, 'sort_order' => 2]);
    refsArticle('specialist', 'Anna', [...$refs, 'sort_order' => 3]);
    refsArticle('specialist', 'Draft', [...$refs, 'status' => PageStatus::Draft]);
    refsArticle('reviewer', 'Reviewer', $refs);
    refsArticle('specialist', 'Gone', $refs)->delete();

    $specialists = refsFields($service)['specialists'];

    expect(refsNames($specialists))->toBe(['Zoe', 'Yan', 'Anna'])
        ->and($specialists[0]['url'])->toBe('/team/zoe');
});

it('rebuilds the reverse index from scratch', function (): void {
    $divorce = refsArticle('legal_service', 'Divorce');
    $contracts = refsArticle('legal_service', 'Contracts');
    $anna = refsArticle('specialist', 'Anna', ['fields' => ['services' => [(string) $contracts->id, 'junk', (string) $divorce->id]]]);
    $reviewer = refsArticle('reviewer', 'Reviewer', ['fields' => ['services' => [(string) $divorce->id]]]);
    refsArticle('legal_service', 'Notes', ['fields' => ['services' => [(string) $divorce->id]]]);

    DB::table('tbtop_page_refs')->delete();

    test()->artisan('cms:page-refs:rebuild')->assertSuccessful();

    $rows = DB::table('tbtop_page_refs')->orderBy('page_id')->orderBy('position')
        ->get(['page_id', 'field', 'target_id', 'position'])
        ->map(static fn (object $row): array => (array) $row)
        ->all();

    expect($rows)->toEqual([
        ['page_id' => $anna->id, 'field' => 'services', 'target_id' => $contracts->id, 'position' => 0],
        ['page_id' => $anna->id, 'field' => 'services', 'target_id' => $divorce->id, 'position' => 1],
        ['page_id' => $reviewer->id, 'field' => 'services', 'target_id' => $divorce->id, 'position' => 0],
    ]);
});

it('leaves a page\'s reference to itself out of the reverse index', function (): void {
    $service = refsArticle('legal_service', 'Divorce');
    $specialist = refsArticle('specialist', 'Olena');
    $specialist->update(['fields' => ['services' => [(string) $specialist->id, (string) $service->id]]]);

    expect(DB::table('tbtop_page_refs')->where('page_id', $specialist->id)->pluck('target_id')->all())->toBe([$service->id]);
});

it('rejects on save a reference to the page being edited', function (): void {
    $reviewer = refsArticle('reviewer', 'Ivan');
    $service = refsArticle('legal_service', 'Divorce');

    test()->actingAs(PageEditHelpers::admin(), 'web')->post("/admin/pages/{$reviewer->id}/edit/forms/pageEditor", [
        ...PageEditHelpers::baseEditPayload(),
        'name' => ['en' => 'Ivan'],
        'slug' => ['en' => 'ivan'],
        'type' => 'article',
        'subtype' => 'reviewer',
        'parent_id' => (string) $reviewer->parent_id,
        'field_reviewer__services' => [(string) $service->id, (string) $reviewer->id],
    ])->assertSessionHasErrors('field_reviewer__services.1');

    expect($reviewer->refresh()->fields['services'] ?? null)->toBeNull();
});
