<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
});

function runOwnerBackfillMigration(): void
{
    (require __DIR__ . '/../../database/migrations/2026_08_11_000002_update_cms_user_profiles_add_is_owner_table.php')->up();
}

it('backfills is_owner onto the smallest-id row when a database has profiles but no owner', function (): void {
    // The suite's own migrations already applied is_owner — drop it to
    // simulate a pre-upgrade database, matching the migration's own
    // hasColumn guard idiom (see neighbouring migrations).
    Schema::table('tbtop_user_profiles', function (Blueprint $table): void {
        $table->dropColumn('is_owner');
    });

    // Deliberately inserted out of user-creation order: the backfill target
    // is the smallest PROFILE row id, not the earliest-created user — here
    // that's $second, whose profile row was inserted first.
    $first = AdminUser::create(['name' => 'First', 'email' => 'first@example.com', 'password' => 'x']);
    $second = AdminUser::create(['name' => 'Second', 'email' => 'second@example.com', 'password' => 'x']);
    DB::table('tbtop_user_profiles')->insert([
        ['user_id' => $second->id],
        ['user_id' => $first->id],
    ]);

    runOwnerBackfillMigration();

    expect((bool) DB::table('tbtop_user_profiles')->where('user_id', $second->id)->value('is_owner'))->toBeTrue()
        ->and((bool) DB::table('tbtop_user_profiles')->where('user_id', $first->id)->value('is_owner'))->toBeFalse();
});

it('does not touch an existing owner when re-run', function (): void {
    Schema::table('tbtop_user_profiles', function (Blueprint $table): void {
        $table->dropColumn('is_owner');
    });

    $first = AdminUser::create(['name' => 'First', 'email' => 'first@example.com', 'password' => 'x']);
    $second = AdminUser::create(['name' => 'Second', 'email' => 'second@example.com', 'password' => 'x']);
    DB::table('tbtop_user_profiles')->insert([
        ['user_id' => $first->id],
        ['user_id' => $second->id],
    ]);

    Schema::table('tbtop_user_profiles', function (Blueprint $table): void {
        $table->boolean('is_owner')->default(false);
    });
    DB::table('tbtop_user_profiles')->where('user_id', $second->id)->update(['is_owner' => true]);

    // Rerunning the migration must not overwrite an already-designated
    // owner even though it isn't the smallest id — the backfill always runs
    // but skips as soon as any owner exists.
    runOwnerBackfillMigration();

    expect((bool) DB::table('tbtop_user_profiles')->where('user_id', $first->id)->value('is_owner'))->toBeFalse()
        ->and((bool) DB::table('tbtop_user_profiles')->where('user_id', $second->id)->value('is_owner'))->toBeTrue();
});

// Non-transactional DDL can crash between the column add and the backfill;
// the retry sees the column already present and must still assign the owner
// instead of short-circuiting past the backfill.
it('backfills on rerun when the column exists but no owner was assigned', function (): void {
    $first = AdminUser::create(['name' => 'First', 'email' => 'first@example.com', 'password' => 'x']);
    DB::table('tbtop_user_profiles')->insert([
        ['user_id' => $first->id, 'is_owner' => false],
    ]);

    runOwnerBackfillMigration();

    expect((bool) DB::table('tbtop_user_profiles')->where('user_id', $first->id)->value('is_owner'))->toBeTrue();
});

it('is a no-op on an empty profiles table', function (): void {
    Schema::table('tbtop_user_profiles', function (Blueprint $table): void {
        $table->dropColumn('is_owner');
    });

    runOwnerBackfillMigration();

    expect(Schema::hasColumn('tbtop_user_profiles', 'is_owner'))->toBeTrue()
        ->and(DB::table('tbtop_user_profiles')->count())->toBe(0);
});
