<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Layout\Slots;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;
use Tbtop\Cms\Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
});

/** The page-editor helpers log in themselves; everything else needs one admin per test. */
function sharedActing(): TestCase
{
    return test()->actingAs(PageEditHelpers::admin(), 'web');
}

/** @param list<array<string, mixed>> $blocks */
function sharedPage(array $blocks = []): Page
{
    return Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About'], 'content' => $blocks]);
}

function sharedSimple(string $name = 'Promo'): SharedBlock
{
    return SharedBlock::query()->create(['name' => $name, 'kind' => 'Simple', 'data' => ['heading' => 'Shared heading', 'body' => 'Shared body']]);
}

it('creates a shared block from name and kind and sends the editor to its edit page', function (): void {
    $response = sharedActing()->postJson('/admin/shared-blocks/actions/sharedBlockCreateSave', [
        'payload' => ['form' => ['name' => 'Promo', 'kind' => 'Simple']],
    ])->assertOk();

    $block = SharedBlock::query()->sole();
    expect($block->kind)->toBe('Simple')->and($block->data)->toBe([]);
    expect(collect($response->json('effects'))->firstWhere('kind', 'redirect')['href'] ?? '')->toContain("/admin/shared-blocks/{$block->id}/edit");
});

it('rejects a kind that is not registered', function (): void {
    $response = sharedActing()->postJson('/admin/shared-blocks/actions/sharedBlockCreateSave', [
        'payload' => ['form' => ['name' => 'Promo', 'kind' => 'Ghost']],
    ])->assertOk();

    expect($response->json('effects.0.kind'))->toBe('haltModal')
        ->and(SharedBlock::count())->toBe(0);
});

it('renders the edit page with the block form under the data prefix and saves it back', function (): void {
    $block = sharedSimple();

    $response = sharedActing()->get("/admin/shared-blocks/{$block->id}/edit", ['X-Inertia' => 'true'])->assertOk();
    $form = findNodeByKind($response->json('props.structure'), 'form');
    $names = array_column($form['options']['children'], 'name');

    expect($names)->toContain('name', 'data_heading', 'data_body')
        ->and($response->json('props.data.sharedBlockEditor.data_heading'))->toBe('Shared heading');

    $this->post("/admin/shared-blocks/{$block->id}/edit/forms/sharedBlockEditor", [
        'name' => 'Promo 2', 'data_heading' => 'New heading', 'data_body' => 'New body',
    ])->assertRedirect();

    $block->refresh();
    expect($block->name)->toBe('Promo 2')
        ->and($block->data)->toBe(['heading' => 'New heading', 'body' => 'New body']);
});

it('rejects a save from a form loaded before another editor saved, keeping the first save', function (): void {
    $block = sharedSimple();
    sharedActing();
    $url = "/admin/shared-blocks/{$block->id}/edit";
    $formA = $this->get($url, ['X-Inertia' => 'true'])->assertOk()->json('props.data.sharedBlockEditor');
    $formB = $this->get($url, ['X-Inertia' => 'true'])->assertOk()->json('props.data.sharedBlockEditor');

    $this->post("{$url}/forms/sharedBlockEditor", [...$formA, 'name' => 'A wins', 'data_heading' => 'A heading', 'data_body' => 'A body'])->assertRedirect();
    $this->post("{$url}/forms/sharedBlockEditor", [...$formB, 'name' => 'B overwrites', 'data_heading' => 'B heading', 'data_body' => 'B body'])->assertRedirect();

    $block->refresh();
    expect($block->name)->toBe('A wins')
        ->and($block->data)->toBe(['heading' => 'A heading', 'body' => 'A body']);
    $notify = collect(session('inertia.flash_data')['tbtop.effects'] ?? [])->firstWhere('kind', 'notify');
    expect($notify['level'] ?? null)->toBe('error')
        ->and($notify['message'] ?? null)->toBe(__('tbtop-cms::shared-blocks.conflict.message'));
});

it('offers the shared blocks of a kind behind its Add button and adds a reference, not a copy', function (): void {
    $shared = sharedSimple();
    $page = sharedPage();

    $tabs = PageEditHelpers::formTabs($page->id);
    $addTab = json_encode(end($tabs));
    // The kind's Add button opens a chooser: the shared block by reference, or a fresh inline one.
    expect($addTab)->toContain('pageBlockChoose_Simple')->toContain('pageBlockAddShared_' . $shared->id)->toContain('Promo')->toContain('pageBlockAdd_Simple');

    $this->postJson("/admin/pages/{$page->id}/edit/actions/pageBlockAddShared_{$shared->id}", [
        'payload' => ['form' => PageEditHelpers::baseEditPayload()],
    ])->assertOk();

    $content = $page->refresh()->content;
    expect($content)->toHaveCount(1)
        ->and($content[0]['kind'])->toBe('Simple')
        ->and($content[0]['shared'])->toBe($shared->id)
        ->and($content[0]['data'])->toBe([])
        ->and($content[0]['active'])->toBeFalse();
});

it('shows a reference as a tab without fields, named after the shared block, and keeps the pointer through save', function (): void {
    $shared = sharedSimple();
    $page = sharedPage([['id' => 'ref1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'shared' => $shared->id, 'data' => []]]);

    $tabs = PageEditHelpers::formTabs($page->id);
    expect($tabs[2]['label'])->toBe('Simple · Promo');
    $json = json_encode($tabs[2], JSON_UNESCAPED_SLASHES);
    expect($json)->not->toContain('block_ref1_heading')
        ->toContain('pageBlockDetach_ref1')
        ->toContain("/admin/shared-blocks/{$shared->id}/edit");

    $this->post("/admin/pages/{$page->id}/edit/forms/pageEditor", [
        ...PageEditHelpers::baseEditPayload(), 'block_ref1_active' => true, 'block_ref1_sort' => 0,
    ])->assertRedirect();

    $content = $page->refresh()->content;
    expect($content[0]['shared'])->toBe($shared->id)->and($content[0]['data'])->toBe([]);
});

it('detaches a reference by copying the shared content into the page', function (): void {
    $shared = sharedSimple();
    $page = sharedPage([['id' => 'ref1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'shared' => $shared->id, 'data' => []]]);

    sharedActing()->postJson("/admin/pages/{$page->id}/edit/actions/pageBlockDetach_ref1", [
        'payload' => ['form' => [...PageEditHelpers::baseEditPayload(), 'block_ref1_active' => true, 'block_ref1_sort' => 0]],
    ])->assertOk();

    $content = $page->refresh()->content;
    expect($content[0])->not->toHaveKey('shared')
        ->and($content[0]['data'])->toBe(['heading' => 'Shared heading', 'body' => 'Shared body'])
        ->and($content[0]['id'])->toBe('ref1');
});

it('refuses to delete a shared block that pages still place, and hides delete on its card', function (): void {
    $shared = sharedSimple();
    sharedPage([['id' => 'ref1', 'kind' => 'Simple', 'active' => true, 'shared' => $shared->id, 'data' => []]]);

    $structure = json_encode(sharedActing()->get('/admin/shared-blocks', ['X-Inertia' => 'true'])->assertOk()->json('props.structure'), JSON_UNESCAPED_SLASHES);
    expect($structure)->toContain('Promo')->toContain("/admin/shared-blocks/{$shared->id}/edit")->not->toContain("sharedBlockDelete_{$shared->id}");

    $this->postJson("/admin/shared-blocks/actions/sharedBlockDelete_{$shared->id}", ['payload' => []])->assertNotFound();
    expect(SharedBlock::count())->toBe(1);
});

it('counts a layout slot as a placement and refuses to delete a block a slot still references', function (): void {
    $shared = sharedSimple();
    Layout::query()->create(['slot' => Slots::FOOTER, 'content' => [['id' => 'f1', 'kind' => 'Simple', 'active' => true, 'shared' => $shared->id, 'data' => []]]]);

    $rows = sharedActing()->getJson("/admin/shared-blocks/{$shared->id}/edit/tables/sharedBlockUsage")->assertOk()->json('data.data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe('layout:' . Slots::FOOTER)
        ->and($rows[0]['container'])->toBe('layout');

    $structure = json_encode($this->get('/admin/shared-blocks', ['X-Inertia' => 'true'])->assertOk()->json('props.structure'));
    expect($structure)->not->toContain("sharedBlockDelete_{$shared->id}");
});

it('keeps the pointer of a layout slot through a save of that slot', function (): void {
    $shared = sharedSimple();
    Layout::query()->create(['slot' => Slots::FOOTER, 'content' => [['id' => 'f1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'shared' => $shared->id, 'data' => []]]]);

    sharedActing()->postJson('/admin/layout/' . Slots::FOOTER . '/edit/forms/layoutEditor', ['block_f1_active' => true, 'block_f1_sort' => 0])
        ->assertRedirect();

    $content = Layout::query()->where('slot', Slots::FOOTER)->firstOrFail()->content;
    expect($content[0]['shared'])->toBe($shared->id)
        ->and($content[0]['data'])->toBe([]);
});

it('shows in the add chooser how many pages and slots place each shared block', function (): void {
    $shared = sharedSimple();
    sharedPage([['id' => 'ref1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'shared' => $shared->id, 'data' => []]]);
    Layout::query()->create(['slot' => Slots::FOOTER, 'content' => [['id' => 'f1', 'kind' => 'Simple', 'active' => true, 'shared' => $shared->id, 'data' => []]]]);
    $unused = sharedSimple('Spare');

    $tabs = PageEditHelpers::formTabs(sharedPage()->id);
    $addTab = json_encode(end($tabs));

    expect($addTab)->toContain('Placed in 2 places')->toContain('Not placed anywhere')
        ->and($unused->exists)->toBeTrue();
});

it('lists every page and slot placing the block in the usage table, and detaches one from there', function (): void {
    $shared = sharedSimple();
    $page = sharedPage([
        ['id' => 'ref1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'shared' => $shared->id, 'data' => []],
        ['id' => 'ref2', 'kind' => 'Simple', 'active' => false, 'sort' => 1, 'shared' => $shared->id, 'data' => []],
    ]);
    $other = sharedPage([['id' => 'ref3', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'shared' => $shared->id, 'data' => []]]);

    $lockBefore = $page->fresh()->lock_version;
    $rows = sharedActing()->getJson("/admin/shared-blocks/{$shared->id}/edit/tables/sharedBlockUsage")->assertOk()->json('data.data');
    expect(array_column($rows, 'id'))->toBe(["page:{$page->id}", "page:{$other->id}"])
        ->and($rows[0]['name'])->toBe('About');

    $response = $this->postJson("/admin/shared-blocks/{$shared->id}/edit/actions/sharedBlockUsageDetachConfirm", ['payload' => ['row' => ['id' => "page:{$page->id}"]]])->assertOk();
    expect($response->json('effects.1.href'))->toContain("/admin/shared-blocks/{$shared->id}/edit");

    $content = $page->fresh()->content;
    expect($content[0])->not->toHaveKey('shared')
        ->and($content[0]['data'])->toBe(['heading' => 'Shared heading', 'body' => 'Shared body'])
        ->and($content[1]['active'])->toBeFalse()
        ->and($content[1])->not->toHaveKey('shared')
        ->and($other->fresh()->content[0]['shared'])->toBe($shared->id)
        ->and($page->fresh()->lock_version)->toBeGreaterThan($lockBefore);
});

it('refuses to detach from a page when the editor lacks the pages ability', function (): void {
    config(['tbtop-cms.roles' => true]);
    Gate::define('tbtop-cms.design', static fn (): bool => true);
    Gate::define('tbtop-cms.pages', static fn (): bool => false);
    $shared = sharedSimple();
    $page = sharedPage([['id' => 'ref1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'shared' => $shared->id, 'data' => []]]);

    sharedActing()->postJson("/admin/shared-blocks/{$shared->id}/edit/actions/sharedBlockUsageDetachConfirm", ['payload' => ['row' => ['id' => "page:{$page->id}"]]])->assertForbidden();

    expect($page->fresh()->content[0]['shared'])->toBe($shared->id);
});

it('rechecks usage when deleting, so a placement made after the card rendered is not orphaned', function (): void {
    $shared = sharedSimple();
    // Build the delete handler while the block is unplaced, then place it before the handler runs.
    $structure = json_encode(sharedActing()->get('/admin/shared-blocks', ['X-Inertia' => 'true'])->assertOk()->json('props.structure'));
    expect($structure)->toContain("sharedBlockDelete_{$shared->id}");

    SharedBlock::query()->getConnection()->beforeExecuting(static function (string $sql) use ($shared): void {
        static $placed = false;
        // The handler's own find(): the page structure (and its usage scan) is already built by then.
        if (! $placed && str_contains($sql, 'tbtop_shared_blocks') && str_contains($sql, 'limit 1')) {
            $placed = true;
            sharedPage([['id' => 'ref1', 'kind' => 'Simple', 'active' => true, 'shared' => $shared->id, 'data' => []]]);
        }
    });

    $response = $this->postJson("/admin/shared-blocks/actions/sharedBlockDelete_{$shared->id}", ['payload' => []])->assertOk();

    expect($response->json('effects.0.kind'))->toBe('notify')
        ->and($response->json('effects.0.level'))->toBe('error')
        ->and(SharedBlock::count())->toBe(1);
});

it('gives a reference whose target has another kind no tab, so it cannot be edited or detached into the row', function (): void {
    $other = SharedBlock::query()->create(['name' => 'Other', 'kind' => 'Hero', 'data' => []]);
    $page = sharedPage([['id' => 'ref1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'shared' => $other->id, 'data' => []]]);

    $tabs = PageEditHelpers::formTabs($page->id);
    expect(array_column($tabs, 'label'))->toBe(['General', 'SEO', '+ Add block'])
        ->and(json_encode($tabs))->not->toContain('pageBlockDetach_ref1')->not->toContain('pageBlockEditShared_ref1');
});

it('refuses to delete a shared block only a trashed page places, and marks that page in the usage table', function (): void {
    $shared = sharedSimple();
    $page = sharedPage([['id' => 'ref1', 'kind' => 'Simple', 'active' => true, 'shared' => $shared->id, 'data' => []]]);
    $page->delete();

    $rows = sharedActing()->getJson("/admin/shared-blocks/{$shared->id}/edit/tables/sharedBlockUsage")->assertOk()->json('data.data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe("page:{$page->id}")
        ->and($rows[0]['container'])->toBe('trashed_page');

    $structure = json_encode($this->get('/admin/shared-blocks', ['X-Inertia' => 'true'])->assertOk()->json('props.structure'));
    expect($structure)->not->toContain("sharedBlockDelete_{$shared->id}");
    $this->postJson("/admin/shared-blocks/actions/sharedBlockDelete_{$shared->id}", ['payload' => []])->assertNotFound();

    expect(SharedBlock::count())->toBe(1);
});

it('deletes a shared block nothing places', function (): void {
    $shared = sharedSimple();

    sharedActing()->postJson("/admin/shared-blocks/actions/sharedBlockDelete_{$shared->id}", ['payload' => []])->assertOk();

    expect(SharedBlock::count())->toBe(0);
});

it('turns an inline block into a shared one from the page editor, keeping unsaved edits', function (): void {
    $page = sharedPage([['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'Old']]]);

    sharedActing()->postJson("/admin/pages/{$page->id}/edit/actions/pageBlockShare_blk1", [
        'payload' => ['form' => [...PageEditHelpers::baseEditPayload(), 'block_blk1_heading' => 'Edited', 'block_blk1_body' => '', 'block_blk1_active' => true, 'block_blk1_sort' => 0]],
    ])->assertOk();

    $shared = SharedBlock::query()->sole();
    expect($shared->kind)->toBe('Simple')
        ->and($shared->name)->toBe('Simple — About')
        ->and($shared->data['heading'])->toBe('Edited');

    $content = $page->refresh()->content;
    expect($content[0])->toMatchArray(['id' => 'blk1', 'shared' => $shared->id, 'data' => []]);
});

it('offers and accepts "Make shared" only to an editor with the design ability', function (bool $design): void {
    config(['tbtop-cms.roles' => true]);
    Gate::define('tbtop-cms.pages', static fn (): bool => true);
    Gate::define('tbtop-cms.design', static fn (): bool => $design);
    $page = sharedPage([['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'Old']]]);

    expect(str_contains((string) json_encode(PageEditHelpers::formTabs($page->id)), 'pageBlockShare_blk1'))->toBe($design);

    $response = $this->postJson("/admin/pages/{$page->id}/edit/actions/pageBlockShare_blk1", [
        'payload' => ['form' => [...PageEditHelpers::baseEditPayload(), 'block_blk1_heading' => 'Old', 'block_blk1_active' => true, 'block_blk1_sort' => 0]],
    ]);

    $response->assertStatus($design ? 200 : 403);
    expect(SharedBlock::count())->toBe($design ? 1 : 0)
        ->and(array_key_exists('shared', $page->refresh()->content[0]))->toBe($design);
})->with(['with design' => true, 'without design' => false]);

it('numbers a derived shared-block name that is already taken', function (): void {
    SharedBlock::query()->create(['name' => 'Simple — About', 'kind' => 'Simple', 'data' => []]);
    $page = sharedPage([['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'X']]]);

    sharedActing()->postJson("/admin/pages/{$page->id}/edit/actions/pageBlockShare_blk1", [
        'payload' => ['form' => [...PageEditHelpers::baseEditPayload(), 'block_blk1_heading' => 'X', 'block_blk1_active' => true, 'block_blk1_sort' => 0]],
    ])->assertOk();

    expect(SharedBlock::query()->pluck('name')->all())->toBe(['Simple — About', 'Simple — About 2']);
});
