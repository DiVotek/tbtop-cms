<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tbtop\Cms\Tests\Fixtures\UuidAdminUser;

// Provider order for the affected migrations — proves the corrective
// migration is registered before the FK migration that depends on it.
const USER_KEY_COLUMN_MIGRATIONS = [
    '2026_01_01_000002_create_pages_table',
    '2026_01_01_000004_create_cms_user_profiles_table',
    '2026_01_01_000005_create_redirects_table',
    '2026_01_01_000007_create_cms_templates_table',
    '2026_01_01_000014_update_user_id_columns_match_host_key_type',
    '2026_01_01_000015_update_cms_user_profiles_add_user_id_foreign_table',
    '2026_01_01_000016_update_cms_user_profiles_add_role_table',
    '2026_01_01_000017_create_page_revisions_table',
];

function runUserKeyColumnMigrationsOn(string $connection): void
{
    $previousDefault = config('database.default');
    config(['database.default' => $connection]);

    try {
        foreach (USER_KEY_COLUMN_MIGRATIONS as $name) {
            (require __DIR__ . "/../../database/migrations/{$name}.php")->up();
        }
    } finally {
        config(['database.default' => $previousDefault]);
    }
}

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => UuidAdminUser::class]);

    config(['database.connections.uuid_host_test' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'foreign_key_constraints' => false,
    ]]);

    Schema::connection('uuid_host_test')->create('users', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    runUserKeyColumnMigrationsOn('uuid_host_test');
});

afterEach(function (): void {
    DB::purge('uuid_host_test');
});

it('declares every user-id column as string-shaped (not integer) on a HasUuids host', function (): void {
    $connection = Schema::connection('uuid_host_test');

    expect($connection->getColumnType('tbtop_user_profiles', 'user_id'))->toBe('varchar')
        ->and($connection->getColumnType('tbtop_page_revisions', 'user_id'))->toBe('varchar')
        ->and($connection->getColumnType('tbtop_pages', 'created_by'))->toBe('varchar')
        ->and($connection->getColumnType('tbtop_pages', 'updated_by'))->toBe('varchar')
        ->and($connection->getColumnType('tbtop_templates', 'created_by'))->toBe('varchar')
        ->and($connection->getColumnType('tbtop_templates', 'updated_by'))->toBe('varchar')
        ->and($connection->getColumnType('tbtop_redirects', 'created_by'))->toBe('varchar')
        ->and($connection->getColumnType('tbtop_redirects', 'updated_by'))->toBe('varchar');
});

it('lets a uuid-pk host save a profile row and a page revision snapshot with the uuid stored intact', function (): void {
    $userId = (string) Str::uuid7();
    DB::connection('uuid_host_test')->table('users')->insert([
        'id' => $userId,
        'name' => 'Admin',
        'email' => 'uuid-admin@example.com',
        'password' => 'secret',
    ]);

    DB::connection('uuid_host_test')->table('tbtop_user_profiles')->insert([
        'user_id' => $userId,
    ]);

    $storedProfileUserId = DB::connection('uuid_host_test')->table('tbtop_user_profiles')->where('user_id', $userId)->value('user_id');
    expect($storedProfileUserId)->toBe($userId);

    $pageId = DB::connection('uuid_host_test')->table('tbtop_pages')->insertGetId([
        'type' => 'page',
        'name' => json_encode(['en' => 'About']),
        'status' => 'published',
    ]);

    DB::connection('uuid_host_test')->table('tbtop_page_revisions')->insert([
        'page_id' => $pageId,
        'user_id' => $userId,
        'kind' => 'create',
        'payload' => json_encode(['name' => ['en' => 'About']]),
        'created_at' => now(),
    ]);

    $storedRevisionUserId = DB::connection('uuid_host_test')->table('tbtop_page_revisions')->where('page_id', $pageId)->value('user_id');
    expect($storedRevisionUserId)->toBe($userId);
});
