<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Events;

/**
 * Raised when a CmsUserProfile row is created for a user, granting cms admin
 * access. A roles/permissions extension subscribes to this to seed its own
 * per-user assignments (e.g. a default role) for the newly granted user.
 */
final class CmsAccessGranted
{
    public function __construct(public readonly int | string $userId) {}
}
