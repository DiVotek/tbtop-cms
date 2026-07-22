<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Admin\Enums\CmsRole;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

// RefreshDatabase only refreshes the default ("testing") connection, so it
// coexists with the throwaway role_migration_test sqlite connection the
// other tests in this file use — needed here so the final test gets a clean
// table under the real migration stack.
uses(RefreshDatabase::class);

// Same rationale as CmsUserProfileForeignKeyTest: a throwaway sqlite
// connection replicating the exact pre-migration shape, so the migration's
// up() runs against a genuinely pre-existing row instead of RefreshDatabase's
// always-empty table (every package migration — including this one — runs
// fresh before each test, so there is never a pre-existing row to backfill
// through the normal test database).
beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);

    config(['database.connections.role_migration_test' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'foreign_key_constraints' => false,
    ]]);

    Schema::connection('role_migration_test')->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    // Pre-migration shape: no role column yet (mirrors
    // create_cms_user_profiles_table + update_..._add_user_id_foreign_table,
    // before update_cms_user_profiles_add_role_table runs).
    Schema::connection('role_migration_test')->create('cms_user_profiles', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id')->unique();
        $table->string('locale')->nullable();
        $table->string('avatar_media_id')->nullable();
        $table->string('display_name')->nullable();
        $table->timestamps();
    });
});

afterEach(function (): void {
    DB::purge('role_migration_test');
});

function runRoleMigrationOn(string $connection): void
{
    $previousDefault = config('database.default');
    config(['database.default' => $connection]);

    try {
        (require __DIR__ . '/../../database/migrations/2026_01_01_000016_update_cms_user_profiles_add_role_table.php')->up();
    } finally {
        config(['database.default' => $previousDefault]);
    }
}

it('backfills existing profile rows to superadministrator', function (): void {
    $userId = DB::connection('role_migration_test')->table('users')->insertGetId([
        'name' => 'A', 'email' => 'existing@example.com', 'password' => 'secret',
    ]);
    DB::connection('role_migration_test')->table('cms_user_profiles')->insert(['user_id' => $userId]);

    runRoleMigrationOn('role_migration_test');

    $role = DB::connection('role_migration_test')->table('cms_user_profiles')->where('user_id', $userId)->value('role');
    expect($role)->toBe(CmsRole::SuperAdministrator->value);
});

it('defaults new rows to editor once the migration has run', function (): void {
    runRoleMigrationOn('role_migration_test');

    $userId = DB::connection('role_migration_test')->table('users')->insertGetId([
        'name' => 'B', 'email' => 'new@example.com', 'password' => 'secret',
    ]);
    // Insert without a role column — relies on the DB-level default set by
    // the migration's second Schema::table()->change() call.
    DB::connection('role_migration_test')->table('cms_user_profiles')->insert(['user_id' => $userId]);

    $role = DB::connection('role_migration_test')->table('cms_user_profiles')->where('user_id', $userId)->value('role');
    expect($role)->toBe(CmsRole::Editor->value);
});

it('backfills multiple existing rows without touching unrelated columns', function (): void {
    $userA = DB::connection('role_migration_test')->table('users')->insertGetId([
        'name' => 'A', 'email' => 'a@example.com', 'password' => 'secret',
    ]);
    $userB = DB::connection('role_migration_test')->table('users')->insertGetId([
        'name' => 'B', 'email' => 'b@example.com', 'password' => 'secret',
    ]);
    DB::connection('role_migration_test')->table('cms_user_profiles')->insert([
        ['user_id' => $userA, 'display_name' => 'Alpha'],
        ['user_id' => $userB, 'display_name' => 'Beta'],
    ]);

    runRoleMigrationOn('role_migration_test');

    $rows = DB::connection('role_migration_test')->table('cms_user_profiles')->orderBy('user_id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->role)->toBe(CmsRole::SuperAdministrator->value)
        ->and($rows[0]->display_name)->toBe('Alpha')
        ->and($rows[1]->role)->toBe(CmsRole::SuperAdministrator->value)
        ->and($rows[1]->display_name)->toBe('Beta');
});

it('defaults a freshly created profile row to editor under the real test-suite migrations', function (): void {
    // Uses the RefreshDatabase-run migrations directly (not the throwaway
    // connection above) — end-to-end proof that the DB-level default from
    // update_cms_user_profiles_add_role_table applies through the normal
    // model + migration stack the rest of the suite runs on.
    $user = AdminUser::create(['name' => 'A', 'email' => 'suite@example.com', 'password' => 'secret']);
    $profile = CmsUserProfile::query()->create(['user_id' => $user->id]);

    expect($profile->fresh()->role)->toBe(CmsRole::Editor);
});
