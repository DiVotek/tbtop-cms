<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function auditAdmin(): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'secret',
    ]);
    CmsUserProfile::query()->forceCreate(['user_id' => $admin->id]);

    return $admin;
}

it('records the causer and subject label when an admin changes an audited model', function (): void {
    $admin = auditAdmin();
    test()->actingAs($admin, 'web');

    $layout = Layout::query()->create(['slot' => 'header', 'content' => []]);

    $activity = Activity::query()->sole();

    expect($activity->log_name)->toBe('tbtop-cms')
        ->and($activity->event)->toBe('created')
        ->and($activity->subject->is($layout))->toBeTrue()
        ->and($activity->causer->is($admin))->toBeTrue()
        ->and(data_get($activity->properties, 'subject_label'))->toBe('header');
});

it('does not record anything when no admin is signed in', function (): void {
    Layout::query()->create(['slot' => 'header', 'content' => []]);

    expect(Activity::query()->count())->toBe(0);
});

it('does not record a change to a field outside the audited set', function (): void {
    test()->actingAs(auditAdmin(), 'web');
    $layout = Layout::query()->create(['slot' => 'header', 'content' => []]);
    Activity::query()->delete();

    $layout->touch();

    expect(Activity::query()->count())->toBe(0);
});

it('summarizes a block field as a count and kinds rather than storing its content', function (): void {
    test()->actingAs(auditAdmin(), 'web');
    $layout = Layout::query()->create(['slot' => 'header', 'content' => []]);
    Activity::query()->delete();

    $layout->update(['content' => [
        ['id' => 'b1', 'kind' => 'Hero', 'data' => ['title' => 'Not stored in the audit log']],
        ['id' => 'b2', 'kind' => 'Simple', 'data' => []],
    ]]);

    $activity = Activity::query()->sole();

    expect(data_get($activity->attribute_changes, 'attributes.content'))
        ->toBe(['count' => 2, 'kinds' => ['Hero', 'Simple']])
        ->and($activity->attribute_changes->toJson())->not->toContain('Not stored in the audit log');
});

it('does not resurrect the v4 properties.attributes/old shape', function (): void {
    test()->actingAs(auditAdmin(), 'web');

    Layout::query()->create(['slot' => 'header', 'content' => []]);

    $activity = Activity::query()->sole();

    expect($activity->properties->has('attributes'))->toBeFalse()
        ->and($activity->properties->has('old'))->toBeFalse();
});

it('flags a block field whose order changed while its ids stayed the same', function (): void {
    test()->actingAs(auditAdmin(), 'web');
    $layout = Layout::query()->create(['slot' => 'header', 'content' => [
        ['id' => 'b1', 'kind' => 'Hero', 'data' => []],
        ['id' => 'b2', 'kind' => 'Simple', 'data' => []],
    ]]);
    Activity::query()->delete();

    $layout->update(['content' => [
        ['id' => 'b2', 'kind' => 'Simple', 'data' => []],
        ['id' => 'b1', 'kind' => 'Hero', 'data' => []],
    ]]);

    expect(data_get(Activity::query()->sole()->properties, 'context.content_order_changed'))->toBeTrue();
});

it('does not flag an order change when only a block\'s data changed', function (): void {
    test()->actingAs(auditAdmin(), 'web');
    $layout = Layout::query()->create(['slot' => 'header', 'content' => [
        ['id' => 'b1', 'kind' => 'Hero', 'data' => ['title' => 'Before']],
        ['id' => 'b2', 'kind' => 'Simple', 'data' => []],
    ]]);
    Activity::query()->delete();

    $layout->update(['content' => [
        ['id' => 'b1', 'kind' => 'Hero', 'data' => ['title' => 'After']],
        ['id' => 'b2', 'kind' => 'Simple', 'data' => []],
    ]]);

    expect(data_get(Activity::query()->sole()->properties, 'context.content_order_changed'))->toBeFalse();
});
