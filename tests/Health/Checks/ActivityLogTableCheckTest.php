<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Health\Checks\ActivityLogTableCheck;
use Tbtop\Cms\Health\HealthStatus;

uses(RefreshDatabase::class);

it('is ok when the activity log table has the v5 attribute_changes column', function (): void {
    $result = (new ActivityLogTableCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
});
