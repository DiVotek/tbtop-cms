<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Form\Notifications\FormSubmissionPayload;
use Tbtop\Cms\Notifications\Audience;
use Tbtop\Cms\Notifications\Models\NotificationLog;
use Tbtop\Cms\Notifications\NotificationLogStatus;
use Tbtop\Cms\Notifications\Notifications;
use Tbtop\Cms\Notifications\Recipient;
use Tbtop\Cms\Tests\Fixtures\UlidNotifiableUser;

// DDL on the default connection: on MySQL it must land in the worker database, not the shared base one.
uses(RefreshDatabase::class);

it('delivers a bell notification to a ulid-keyed user: the notifications row carries the ulid and the log row is sent', function (): void {
    // A host whose users are HasUlids. The suite migrated `notifications` for the
    // workbench's integer-keyed users, so build it again the way the package
    // migration builds it for this host.
    config(['tbtop-cms.admin.model' => UlidNotifiableUser::class]);
    Schema::drop('notifications');
    (require __DIR__ . '/../../database/migrations/2026_01_01_000020_create_notifications_table.php')->up();

    Schema::create('ulid_users', function (Blueprint $table): void {
        $table->ulid('id')->primary();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->timestamps();
    });

    $staff = UlidNotifiableUser::create(['name' => 'Ops', 'email' => 'ops@example.test', 'password' => 'secret']);

    $deliveries = Notifications::send('form.submission', new FormSubmissionPayload(
        leadIdentity: 'Ada',
        pageName: 'Contact',
        locale: 'en',
        submittedAt: '22.10.2026 10:00',
        fields: [],
        actionUrl: 'https://example.test/admin/form-submissions/1',
    ), [new Recipient(Audience::Staff, 'en', notifiable: $staff)]);

    $log = NotificationLog::query()->where('channel', 'bell')->sole();
    expect($log->error)->toBeNull()
        ->and($log->status)->toBe(NotificationLogStatus::Sent)
        ->and($deliveries)->toHaveCount(1)
        ->and($deliveries[0]->failed())->toBeFalse();

    $row = DB::table('notifications')->sole();
    expect($row->notifiable_id)->toBe($staff->getKey())
        ->and($row->notifiable_type)->toBe($staff->getMorphClass())
        ->and($staff->notifications()->count())->toBe(1);
});
