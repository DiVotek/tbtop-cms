<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\ConcertPageType;
use Tbtop\Cms\Tests\Fixtures\ContactsPageType;
use Tbtop\Cms\Tests\Fixtures\EventPageType;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;
use Tbtop\Cms\Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    PageTypeRegistry::extend(fn (): array => [ContactsPageType::class, EventPageType::class, ConcertPageType::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function subtypeAdmin(): TestCase
{
    return test()->actingAs(PageEditHelpers::admin(), 'web');
}

/** @return array<string, mixed> the edit page's structure */
function editStructure(Page $page): array
{
    return test()->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])->assertOk()->json('props.structure');
}

/** @param  array<string, mixed>  $overrides */
function savePage(Page $page, array $overrides): TestResponse
{
    return test()->post("/admin/pages/{$page->id}/edit/forms/pageEditor", [...PageEditHelpers::baseEditPayload(), ...$overrides]);
}

it('offers top-level types under "Type", a host one included, and only subtypes of the page\'s own type under "Subtype"', function (): void {
    $event = Page::create(['type' => 'event', 'status' => PageStatus::Draft, 'name' => ['en' => 'Gig']]);
    $category = Page::create(['type' => 'category', 'status' => PageStatus::Draft, 'name' => ['en' => 'Blog']]);
    subtypeAdmin();

    $structure = editStructure($event);

    expect(array_column(findFieldByName($structure, 'type')['options']['options'], 'value'))->toBe(['page', 'category', 'article', 'event'])
        ->and(array_column(findFieldByName($structure, 'subtype')['options']['options'], 'value'))->toBe(['', 'concert'])
        ->and(findFieldByName(editStructure($category), 'subtype'))->toBeNull();
});

it('writes the picked subtype to `subtype` and leaves `type` alone', function (): void {
    $page = Page::create(['type' => 'event', 'status' => PageStatus::Draft, 'name' => ['en' => 'Gig']]);
    subtypeAdmin();

    savePage($page, ['type' => 'event', 'subtype' => 'concert'])->assertRedirect()->assertSessionHasNoErrors();

    expect($page->refresh()->only(['type', 'subtype']))->toBe(['type' => 'event', 'subtype' => 'concert']);
});

it('creates a page with a subtype from the create modal', function (): void {
    subtypeAdmin()->postJson('/admin/pages/actions/pageCreateSave', [
        'payload' => ['form' => [
            'name' => ['en' => 'Contacts'], 'title' => ['en' => ''], 'slug' => ['en' => 'contacts'],
            'type' => 'page', 'subtype_page' => 'contacts', 'parent_id' => null,
        ]],
    ])->assertOk();

    expect(Page::query()->firstOrFail()->only(['type', 'subtype']))->toBe(['type' => 'page', 'subtype' => 'contacts']);
});

it('refuses a type change that would leave the subtype behind, and rejects a subtype of another type', function (): void {
    $retyped = Page::create(['type' => 'event', 'subtype' => 'concert', 'status' => PageStatus::Draft, 'name' => ['en' => 'Gig']]);
    $kept = Page::create(['type' => 'event', 'status' => PageStatus::Draft, 'name' => ['en' => 'Other']]);
    subtypeAdmin();

    savePage($retyped, ['type' => 'page', 'subtype' => 'concert'])->assertSessionHasErrors('type');
    savePage($kept, ['type' => 'event', 'subtype' => 'contacts'])->assertSessionHasErrors('subtype');

    expect($retyped->refresh()->only(['type', 'subtype']))->toBe(['type' => 'event', 'subtype' => 'concert'])
        ->and($kept->refresh()->subtype)->toBeNull();
});

it('keeps the subtype select in reach after a type change until the subtype is cleared, then saves both', function (): void {
    $page = Page::create(['type' => 'event', 'subtype' => 'concert', 'status' => PageStatus::Draft, 'name' => ['en' => 'Gig']]);
    subtypeAdmin();
    $subtype = findFieldByName(editStructure($page), 'subtype');

    expect(isShownFor($subtype, ['type' => 'page', 'subtype' => 'concert']))->toBeTrue()
        ->and(isShownFor($subtype, ['type' => 'page', 'subtype' => '']))->toBeFalse()
        ->and(isShownFor($subtype, ['type' => 'event', 'subtype' => '']))->toBeTrue();

    savePage($page, ['type' => 'page', 'subtype' => null])->assertSessionHasNoErrors();

    expect($page->refresh()->only(['type', 'subtype']))->toBe(['type' => 'page', 'subtype' => null]);
});

it('reads tree rules from the base type: a subtype that requires a parent does not make its page need one', function (): void {
    $page = Page::create(['type' => 'event', 'subtype' => 'concert', 'status' => PageStatus::Draft, 'name' => ['en' => 'Gig']]);

    expect($page->exists)->toBeTrue()->and($page->parent_id)->toBeNull();
});

it('shows the subtype in the pages list and filters by it', function (): void {
    Page::create(['type' => 'event', 'subtype' => 'concert', 'status' => PageStatus::Draft, 'name' => ['en' => 'Gig']]);
    Page::create(['type' => 'event', 'status' => PageStatus::Draft, 'name' => ['en' => 'Fair']]);

    $rows = subtypeAdmin()->getJson('/admin/pages/tables/pages?tab=all&filters[subtype]=concert')->assertOk()->json('data.data');

    expect(array_column($rows, 'name'))->toBe(['Gig'])
        ->and(array_column($rows, 'subtype'))->toBe(['Concert']);
});
