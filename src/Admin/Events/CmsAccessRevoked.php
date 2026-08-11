<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Events;

/**
 * Raised when a CmsUserProfile row is deleted, revoking cms admin access. A
 * roles/permissions extension subscribes to this to clean up its own
 * per-user assignments left behind by the now-revoked user.
 */
final class CmsAccessRevoked
{
    public function __construct(public readonly int | string $userId) {}
}
