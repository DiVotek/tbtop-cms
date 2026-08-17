<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Health\Checks\DefaultOwnerAndLanguageCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

function runOwnerAndLanguageCheck(): HealthCheckResult
{
    return app(DefaultOwnerAndLanguageCheck::class)->run();
}

it('errors when no owner and no default frontend language exist', function (): void {
    expect(runOwnerAndLanguageCheck()->status)->toBe(HealthStatus::Error);
});

it('errors when a default language exists but no owner does', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);

    expect(runOwnerAndLanguageCheck()->status)->toBe(HealthStatus::Error);
});

it('errors when an owner exists but no active default language does', function (): void {
    $user = AdminUser::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'is_owner' => true]);

    expect(runOwnerAndLanguageCheck()->status)->toBe(HealthStatus::Error);
});

it('is ok when both an owner and an active default language exist', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    $user = AdminUser::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'is_owner' => true]);
    app(Languages::class)->refresh();

    expect(runOwnerAndLanguageCheck()->status)->toBe(HealthStatus::Ok);
});
