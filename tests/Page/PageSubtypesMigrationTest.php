<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Tests\Fixtures\ConcertPageType;
use Tbtop\Cms\Tests\Fixtures\ContactsPageType;
use Tbtop\Cms\Tests\Fixtures\EventPageType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PageTypeRegistry::extend(fn (): array => [ContactsPageType::class, EventPageType::class, ConcertPageType::class]);
});

function subtypesMigration(): Migration
{
    return require dirname(__DIR__, 2) . '/database/migrations/2026_10_06_000000_add_subtype_and_fields_to_pages_table.php';
}

it('moves a subtype key out of `type` into `subtype` and strips `owner` from content rows, trashed pages included', function (): void {
    // Rows as #242 stored them — the subtype key in `type`, type rows marked with `owner` — written past
    // the observer, which would read `concert`'s own tree rules.
    $contacts = Page::createQuietly(['type' => 'contacts', 'status' => PageStatus::Published, 'name' => ['en' => 'Contacts'], 'content' => [
        ['id' => 'a', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'owner' => 'contacts', 'data' => ['heading' => 'Office']],
        ['id' => 'b', 'kind' => 'Hero', 'active' => false, 'sort' => 1, 'data' => []],
    ]]);
    $concert = Page::createQuietly(['type' => 'concert', 'status' => PageStatus::Draft, 'name' => ['en' => 'Gig']]);
    $plain = Page::createQuietly(['type' => 'event', 'status' => PageStatus::Draft, 'name' => ['en' => 'Fair']]);
    $trashed = Page::createQuietly(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'Old'], 'content' => [
        ['id' => 'c', 'kind' => 'Hero', 'active' => true, 'sort' => 0, 'owner' => 'gone', 'data' => []],
    ]]);
    $trashed->delete();

    $migration = subtypesMigration();
    $migration->up();
    $migration->up();

    expect($contacts->refresh()->only(['type', 'subtype']))->toBe(['type' => 'page', 'subtype' => 'contacts'])
        ->and($concert->refresh()->only(['type', 'subtype']))->toBe(['type' => 'event', 'subtype' => 'concert'])
        ->and($plain->refresh()->only(['type', 'subtype']))->toBe(['type' => 'event', 'subtype' => null])
        ->and($contacts->content)->toBeIgnoringKeyOrder([
            ['id' => 'a', 'kind' => 'Simple', 'active' => true, 'sort' => 0, 'data' => ['heading' => 'Office']],
            ['id' => 'b', 'kind' => 'Hero', 'active' => false, 'sort' => 1, 'data' => []],
        ])
        ->and(Page::withTrashed()->findOrFail($trashed->id)->content)->toBeIgnoringKeyOrder([
            ['id' => 'c', 'kind' => 'Hero', 'active' => true, 'sort' => 0, 'data' => []],
        ]);
});

it('moves a subtype back into `type` on down(), trashed pages included', function (): void {
    $concert = Page::createQuietly(['type' => 'event', 'subtype' => 'concert', 'status' => PageStatus::Draft, 'name' => ['en' => 'Gig']]);
    $plain = Page::createQuietly(['type' => 'event', 'status' => PageStatus::Draft, 'name' => ['en' => 'Fair']]);
    $trashed = Page::createQuietly(['type' => 'page', 'subtype' => 'contacts', 'status' => PageStatus::Draft, 'name' => ['en' => 'Old']]);
    $trashed->delete();

    subtypesMigration()->down();

    expect(DB::table('tbtop_pages')->orderBy('id')->pluck('type', 'id')->all())->toBe([
        $concert->id => 'concert',
        $plain->id => 'event',
        $trashed->id => 'contacts',
    ]);
});
