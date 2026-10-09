<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageRevision;
use Tbtop\Cms\SharedBlock\Events\SharedBlockSaved;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;

// The other editor's commit: a raw lock_version bump right after the save reads the row it compares.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
});

/**
 * Bumps the row's lock_version in SQL on its $nth retrieval; 'fired' counts the bumps.
 *
 * @param  class-string<Page|SharedBlock>  $model
 */
function raceBumpOnRead(string $model, string $table, int $id, int $nth): ArrayObject
{
    $state = new ArrayObject(['reads' => 0, 'fired' => 0]);

    $model::retrieved(static function (Page | SharedBlock $row) use ($state, $table, $id, $nth): void {
        if ((int) $row->getKey() !== $id || ++$state['reads'] !== $nth) {
            return;
        }

        DB::table($table)->where('id', $id)->update(['lock_version' => DB::raw('lock_version + 1')]);
        $state['fired']++;
    });

    return $state;
}

/** @return array<string, mixed>|null */
function raceNotify(): ?array
{
    return collect(session('inertia.flash_data')['tbtop.effects'] ?? [])->firstWhere('kind', 'notify');
}

it('a page save loses to a write that lands between its read and its write', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'Original']]);
    $slugsBefore = DB::table('tbtop_page_slugs')->where('page_id', $page->id)->get(['locale', 'slug', 'path'])->all();
    $refsBefore = DB::table('tbtop_page_refs')->where('page_id', $page->id)->count();
    $revisionsBefore = PageRevision::query()->where('page_id', $page->id)->count();
    $activityBefore = Activity::query()->count();
    $admin = PageEditHelpers::admin();

    Event::fake([PageSaved::class]);
    // 3rd read: PageForm::save()'s findOrFail, after the edit page and its form record.
    $race = raceBumpOnRead(Page::class, 'tbtop_pages', $page->id, 3);

    test()->actingAs($admin, 'web')->post(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        [...PageEditHelpers::baseEditPayload(), 'lock_version' => 0, 'name' => ['en' => 'Loser'], 'slug' => ['en' => 'loser']],
    )->assertRedirect();

    expect($race['fired'])->toBe(1);

    $notify = raceNotify();
    expect($notify['level'] ?? null)->toBe('error')
        ->and($notify['message'] ?? null)->toBe(__('tbtop-cms::pages.conflict.message'))
        ->and(collect(session('inertia.flash_data')['tbtop.effects'] ?? [])->pluck('kind'))->not->toContain('redirect');

    $row = DB::table('tbtop_pages')->where('id', $page->id)->first();
    expect((int) $row->lock_version)->toBe(1)
        ->and(json_decode((string) $row->name, true))->toBe(['en' => 'Original'])
        ->and(PageRevision::query()->where('page_id', $page->id)->count())->toBe($revisionsBefore)
        ->and(Activity::query()->count())->toBe($activityBefore)
        ->and(DB::table('tbtop_page_slugs')->where('page_id', $page->id)->get(['locale', 'slug', 'path'])->all())->toEqual($slugsBefore)
        ->and(DB::table('tbtop_page_refs')->where('page_id', $page->id)->count())->toBe($refsBefore);
    Event::assertNotDispatched(PageSaved::class);
});

it('a shared block save loses to a write that lands between its read and its write', function (): void {
    $block = SharedBlock::query()->create(['name' => 'Promo', 'kind' => 'Simple', 'data' => ['heading' => 'H', 'body' => 'B']]);
    $activityBefore = Activity::query()->count();
    test()->actingAs(PageEditHelpers::admin(), 'web');

    Event::fake([SharedBlockSaved::class]);
    // 2nd read: the block() handed to SharedBlockForm::update(), after the view's.
    $race = raceBumpOnRead(SharedBlock::class, 'tbtop_shared_blocks', $block->id, 2);

    test()->post("/admin/shared-blocks/{$block->id}/edit/forms/sharedBlockEditor", [
        'lock_version' => 0, 'name' => 'Loser', 'data_heading' => 'Loser heading', 'data_body' => 'Loser body',
    ])->assertRedirect();

    expect($race['fired'])->toBe(1);

    $notify = raceNotify();
    expect($notify['level'] ?? null)->toBe('error')
        ->and($notify['message'] ?? null)->toBe(__('tbtop-cms::shared-blocks.conflict.message'));

    $row = DB::table('tbtop_shared_blocks')->where('id', $block->id)->first();
    expect((int) $row->lock_version)->toBe(1)
        ->and($row->name)->toBe('Promo')
        ->and(json_decode((string) $row->data, true))->toBeIgnoringKeyOrder(['heading' => 'H', 'body' => 'B'])
        ->and(Activity::query()->count())->toBe($activityBefore);
    Event::assertNotDispatched(SharedBlockSaved::class);
});
