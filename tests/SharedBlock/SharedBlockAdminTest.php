<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
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

    $edit = json_encode(sharedActing()->get("/admin/shared-blocks/{$shared->id}/edit", ['X-Inertia' => 'true'])->assertOk()->json('props.structure'), JSON_UNESCAPED_SLASHES);
    expect($edit)->toContain('/admin/layout/' . Slots::FOOTER . '/edit');

    $structure = json_encode($this->get('/admin/shared-blocks', ['X-Inertia' => 'true'])->assertOk()->json('props.structure'));
    expect($structure)->not->toContain("sharedBlockDelete_{$shared->id}");
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

it('shows a reference whose target has another kind as missing, with no edit or detach', function (): void {
    $other = SharedBlock::query()->create(['name' => 'Other', 'kind' => 'Hero', 'data' => []]);
    $page = sharedPage([['id' => 'ref1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'shared' => $other->id, 'data' => []]]);

    $tabs = PageEditHelpers::formTabs($page->id);
    $json = json_encode($tabs[2]);
    expect($tabs[2]['label'])->toBe("Simple · #{$other->id}")
        ->and($json)->not->toContain('pageBlockDetach_ref1')->not->toContain('pageBlockEditShared_ref1');
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

it('numbers a derived shared-block name that is already taken', function (): void {
    SharedBlock::query()->create(['name' => 'Simple — About', 'kind' => 'Simple', 'data' => []]);
    $page = sharedPage([['id' => 'blk1', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'X']]]);

    sharedActing()->postJson("/admin/pages/{$page->id}/edit/actions/pageBlockShare_blk1", [
        'payload' => ['form' => [...PageEditHelpers::baseEditPayload(), 'block_blk1_heading' => 'X', 'block_blk1_active' => true, 'block_blk1_sort' => 0]],
    ])->assertOk();

    expect(SharedBlock::query()->pluck('name')->all())->toBe(['Simple — About', 'Simple — About 2']);
});
