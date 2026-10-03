<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\ContactsPageType;
use Tbtop\Cms\Tests\Fixtures\FaqSchemaBlock;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;
use Tbtop\Cms\Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class, HeroBlock::class]]);
    config(['tbtop-cms.pages.types' => [ContactsPageType::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

function typeActing(): TestCase
{
    return test()->actingAs(PageEditHelpers::admin(), 'web');
}

/** @param list<array<string, mixed>> $content */
function contactsPage(array $content = [], string $type = 'contacts'): Page
{
    return Page::create(['type' => $type, 'status' => PageStatus::Published, 'name' => ['en' => 'Contacts'], 'content' => $content]);
}

/**
 * @param  array<string, mixed>  $props
 * @return list<string>
 */
function renderedKinds(array $props): array
{
    $elements = $props['render']['elements'];

    return array_map(static fn (string $id): string => $elements[$id]['type'], $elements[$props['render']['root']]['children']);
}

/** @return array<string, array<string, mixed>> kind => row, for rows owned by $type */
function ownedRows(Page $page, string $type): array
{
    $rows = [];
    foreach ($page->refresh()->content as $row) {
        if (($row['owner'] ?? null) === $type) {
            $rows[$row['kind']] = $row;
        }
    }

    return $rows;
}

it('creates the type\'s blocks with the page, after any content it was created with', function (): void {
    $page = contactsPage([['id' => 'free', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => []]]);

    $owned = ownedRows($page, 'contacts');
    expect(array_keys($owned))->toBe(['Simple', 'Hero'])
        ->and($owned['Simple']['sort'])->toBe(1)
        ->and($owned['Hero']['sort'])->toBe(2)
        ->and($page->content[0]['id'])->toBe('free');
});

it('puts the general block\'s form on the General tab and the content block in its own tab, without a remove button', function (): void {
    $page = contactsPage();
    $owned = ownedRows($page, 'contacts');
    $tabs = PageEditHelpers::formTabs($page->id);

    expect(findFieldByName($tabs[0]['body'], "block_{$owned['Simple']['id']}_heading"))->not->toBeNull()
        ->and(array_column($tabs, 'label'))->toContain('Hero');

    $heroTab = collect($tabs)->firstWhere('label', 'Hero');
    $actions = [];
    collectNodesByKind($heroTab['body'], 'action', $actions);
    expect(array_column(array_column($actions, 'options'), 'name'))->not->toContain("pageBlockRemove_{$owned['Hero']['id']}");
});

it('saves general-tab values into the owned row and keeps the owner', function (): void {
    $page = contactsPage();
    $owned = ownedRows($page, 'contacts');

    typeActing()->post("/admin/pages/{$page->id}/edit/forms/pageEditor", [
        ...PageEditHelpers::baseEditPayload(),
        'type' => 'contacts',
        "block_{$owned['Simple']['id']}_heading" => 'Main office',
        "block_{$owned['Hero']['id']}_title" => ['en' => 'Find us'],
        "block_{$owned['Hero']['id']}_active" => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $saved = ownedRows($page, 'contacts');
    expect($saved['Simple']['data']['heading'])->toBe('Main office')
        ->and($saved['Hero']['data']['title'])->toBe(['en' => 'Find us']);
});

it('refuses to remove an owned block through the block-remove action', function (): void {
    $page = contactsPage();
    $owned = ownedRows($page, 'contacts');

    // No remove action is registered for an owned row: the request has nothing to hit.
    typeActing()->postJson("/admin/pages/{$page->id}/edit/actions/pageBlockRemove_{$owned['Hero']['id']}", [
        'payload' => ['form' => [...PageEditHelpers::baseEditPayload(), 'type' => 'contacts']],
    ])->assertNotFound();

    expect(ownedRows($page, 'contacts'))->toHaveKey('Hero');
});

it('keeps the old type\'s rows dormant on a type switch and wakes them with their data on the way back', function (): void {
    $page = contactsPage();
    $owned = ownedRows($page, 'contacts');
    $page->content = array_map(
        static fn (array $row): array => $row['id'] === $owned['Simple']['id'] ? [...$row, 'data' => ['heading' => 'Main office']] : $row,
        $page->content,
    );
    $page->save();

    $page->type = 'page';
    $page->save();

    $tabs = PageEditHelpers::formTabs($page->id);
    expect(array_column($tabs, 'label'))->not->toContain('Hero')
        ->and(ownedRows($page, 'contacts'))->toHaveCount(2);
    $texts = [];
    collectNodesByKind($tabs[0]['body'], 'displayHtml', $texts);
    expect(json_encode($texts))->toContain('Contacts');

    // A form save while dormant must carry the rows through untouched — data stays flat.
    test()->post("/admin/pages/{$page->id}/edit/forms/pageEditor", [...PageEditHelpers::baseEditPayload(), 'type' => 'page'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(ownedRows($page, 'contacts')['Simple']['data'])->toBe(['heading' => 'Main office']);

    $page->type = 'contacts';
    $page->save();

    $woken = ownedRows($page, 'contacts');
    expect($woken['Simple']['id'])->toBe($owned['Simple']['id'])
        ->and($woken['Simple']['data']['heading'])->toBe('Main office')
        ->and(count($page->content))->toBe(2);
});

it('drops the dormant rows on purge and leaves the rest of the page alone', function (): void {
    $page = contactsPage([['id' => 'free', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'Keep']]]);
    $page->type = 'page';
    $page->save();

    typeActing()->postJson("/admin/pages/{$page->id}/edit/actions/pageTypeBlocksPurge", [
        'payload' => ['form' => [...PageEditHelpers::baseEditPayload(), 'block_free_heading' => 'Keep', 'block_free_active' => true]],
    ])->assertOk();

    expect(array_column($page->refresh()->content, 'id'))->toBe(['free']);
});

it('renders the general block as page fields and the owned content block in the flow', function (): void {
    $page = contactsPage([['id' => 'free', 'kind' => 'Hero', 'active' => true, 'sort' => 0, 'data' => ['title' => ['en' => 'Free']]]]);
    $owned = ownedRows($page, 'contacts');
    $page->content = array_map(static fn (array $row): array => match ($row['id']) {
        $owned['Simple']['id'] => [...$row, 'data' => ['heading' => 'Main office']],
        $owned['Hero']['id'] => [...$row, 'data' => ['title' => ['en' => 'Owned']]],
        default => $row,
    }, $page->content);
    $page->save();

    $props = test()->get('/' . $page->pathFor('en'), ['X-Inertia' => 'true'])->assertOk()->json('props');
    expect($props['page']['fields']['heading'])->toBe('Main office')
        ->and(renderedKinds($props))->toBe(['Hero', 'Hero']);
});

it('renders nothing of a dormant type: no fields, no blocks', function (): void {
    $page = contactsPage([['id' => 'free', 'kind' => 'Hero', 'active' => true, 'sort' => 0, 'data' => ['title' => ['en' => 'Free']]]]);
    $page->type = 'page';
    $page->save();

    $props = test()->get('/' . $page->pathFor('en'), ['X-Inertia' => 'true'])->assertOk()->json('props');
    expect($props['page']['fields'])->toBe([])
        ->and(renderedKinds($props))->toBe(['Hero']);
});

it('offers neither the type\'s own kinds nor a general kind in "+ Add block"', function (): void {
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class, HeroBlock::class, FaqSchemaBlock::class]]);
    $page = contactsPage();
    $addTab = collect(PageEditHelpers::formTabs($page->id))->firstWhere('label', __('tbtop-cms::pages.tabs_edit.add_block'));
    $actions = [];
    collectNodesByKind($addTab['body'], 'action', $actions);

    expect(array_column($actions, 'name'))->toContain('pageBlockAdd_FaqTest')
        ->not->toContain('pageBlockAdd_Hero')
        ->not->toContain('pageBlockAdd_Simple');
});
