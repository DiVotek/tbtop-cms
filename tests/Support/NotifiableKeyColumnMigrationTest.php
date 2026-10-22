<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\UlidNotifiableUser;

// DDL on the default connection: on MySQL it must land in the worker database, not the shared base one.
uses(RefreshDatabase::class);

const NOTIFICATIONS_CREATE_MIGRATION = '2026_01_01_000020_create_notifications_table';
const NOTIFIABLE_KEY_ALIGNMENT_MIGRATION = '2026_10_22_000000_update_notifications_notifiable_id_match_host_key_type';

afterEach(function (): void {
    // Schema::morphUsingUuids() is process-wide static state.
    Builder::defaultMorphKeyType('int');
});

function runNotificationsMigration(string $name): void
{
    (require __DIR__ . "/../../database/migrations/{$name}.php")->up();
}

/**
 * `notifications` as it was before the column followed the host key: what the
 * package's create migration built with `morphs()`, and what a host-owned
 * `make:notifications-table` copy has. $morphs picks the morph column helper.
 */
function recreateNotificationsTable(string $morphs = 'morphs'): void
{
    Schema::dropIfExists('notifications');
    Schema::create('notifications', function (Blueprint $table) use ($morphs): void {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->{$morphs}('notifiable');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
}

function notifiableIdIsInteger(): bool
{
    return str_contains(strtolower(Schema::getColumnType('notifications', 'notifiable_id')), 'int');
}

function notifiableIdIsNullable(): bool
{
    $column = collect(Schema::getColumns('notifications'))->firstWhere('name', 'notifiable_id');

    return (bool) $column['nullable'];
}

function hasNotifiableMorphIndex(): bool
{
    return Schema::hasIndex('notifications', 'notifications_notifiable_type_notifiable_id_index')
        && Schema::hasIndex('notifications', ['notifiable_type', 'notifiable_id']);
}

function insertNotificationFor(string $notifiableType, int | string | null $notifiableId): void
{
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'notifiable_type' => $notifiableType,
        'notifiable_id' => $notifiableId,
        'data' => '{}',
    ]);
}

it('creates notifiable_id as a string column with the morph index on a HasUlids host', function (): void {
    config(['tbtop-cms.admin.model' => UlidNotifiableUser::class]);
    Schema::drop('notifications');

    runNotificationsMigration(NOTIFICATIONS_CREATE_MIGRATION);

    expect(notifiableIdIsInteger())->toBeFalse()
        ->and(notifiableIdIsNullable())->toBeFalse()
        ->and(hasNotifiableMorphIndex())->toBeTrue();
});

it('creates notifiable_id as an integer column with the morph index on an int-key host', function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    Schema::drop('notifications');

    runNotificationsMigration(NOTIFICATIONS_CREATE_MIGRATION);

    expect(notifiableIdIsInteger())->toBeTrue()
        ->and(hasNotifiableMorphIndex())->toBeTrue();
});

it('creates notifiable_id with the host\'s default morph key type when it chose one, over an int user key', function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    Schema::morphUsingUuids();
    Schema::drop('notifications');

    runNotificationsMigration(NOTIFICATIONS_CREATE_MIGRATION);

    $uuid = (string) Str::uuid();
    insertNotificationFor('App\\Models\\Customer', $uuid);

    expect(notifiableIdIsInteger())->toBeFalse()
        ->and(hasNotifiableMorphIndex())->toBeTrue()
        ->and(DB::table('notifications')->value('notifiable_id'))->toBe($uuid);
});

it('changes an already-migrated integer notifiable_id to a string column on a HasUlids host, keeping its rows and the morph index', function (): void {
    recreateNotificationsTable();
    insertNotificationFor(AdminUser::class, 42);
    config(['tbtop-cms.admin.model' => UlidNotifiableUser::class]);

    runNotificationsMigration(NOTIFIABLE_KEY_ALIGNMENT_MIGRATION);

    $ulid = (string) Str::ulid();
    insertNotificationFor(UlidNotifiableUser::class, $ulid);

    expect(notifiableIdIsInteger())->toBeFalse()
        ->and(notifiableIdIsNullable())->toBeFalse()
        ->and(hasNotifiableMorphIndex())->toBeTrue()
        ->and(DB::table('notifications')->where('notifiable_type', AdminUser::class)->value('notifiable_id'))->toBe('42')
        ->and(DB::table('notifications')->where('notifiable_id', 42)->value('notifiable_type'))->toBe(AdminUser::class)
        ->and(DB::table('notifications')->where('notifiable_id', $ulid)->value('notifiable_type'))->toBe(UlidNotifiableUser::class);
});

it('keeps a nullable integer notifiable_id nullable when it changes it on a HasUlids host', function (): void {
    recreateNotificationsTable('nullableMorphs');
    config(['tbtop-cms.admin.model' => UlidNotifiableUser::class]);

    runNotificationsMigration(NOTIFIABLE_KEY_ALIGNMENT_MIGRATION);

    insertNotificationFor(UlidNotifiableUser::class, null);

    expect(notifiableIdIsInteger())->toBeFalse()
        ->and(notifiableIdIsNullable())->toBeTrue()
        ->and(DB::table('notifications')->value('notifiable_id'))->toBeNull();
});

it('leaves an integer notifiable_id alone on an int-key host', function (): void {
    recreateNotificationsTable();
    config(['tbtop-cms.admin.model' => AdminUser::class]);

    runNotificationsMigration(NOTIFIABLE_KEY_ALIGNMENT_MIGRATION);

    expect(notifiableIdIsInteger())->toBeTrue()
        ->and(hasNotifiableMorphIndex())->toBeTrue();
});

it('leaves a string notifiable_id alone on an int-key host, whose own table may hold other models\' string keys', function (): void {
    recreateNotificationsTable('uuidMorphs');
    $uuid = (string) Str::uuid();
    insertNotificationFor('App\\Models\\Customer', $uuid);
    config(['tbtop-cms.admin.model' => AdminUser::class]);

    runNotificationsMigration(NOTIFIABLE_KEY_ALIGNMENT_MIGRATION);

    expect(notifiableIdIsInteger())->toBeFalse()
        ->and(DB::table('notifications')->value('notifiable_id'))->toBe($uuid);
});

it('leaves notifiable_id alone when the host chose a default morph key type, which owns its morph columns', function (): void {
    recreateNotificationsTable('numericMorphs');
    config(['tbtop-cms.admin.model' => UlidNotifiableUser::class]);
    Schema::morphUsingUuids();

    runNotificationsMigration(NOTIFIABLE_KEY_ALIGNMENT_MIGRATION);

    expect(notifiableIdIsInteger())->toBeTrue();
});

it('runs from the package migration list and skips a host without a notifications table', function (): void {
    expect(DB::table('migrations')->where('migration', NOTIFIABLE_KEY_ALIGNMENT_MIGRATION)->exists())->toBeTrue();

    Schema::drop('notifications');
    config(['tbtop-cms.admin.model' => UlidNotifiableUser::class]);

    runNotificationsMigration(NOTIFIABLE_KEY_ALIGNMENT_MIGRATION);

    expect(Schema::hasTable('notifications'))->toBeFalse();
});
