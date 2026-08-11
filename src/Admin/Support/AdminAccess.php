<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Support;

use Illuminate\Support\Facades\Event;
use Tbtop\Cms\Admin\Events\CmsAccessGranted;
use Tbtop\Cms\Admin\Events\CmsAccessRevoked;
use Tbtop\Cms\Admin\Models\CmsUserProfile;

/**
 * Single write path for granting/revoking cms admin access, shared by
 * UsersPage and the cms:admin:grant/revoke Artisan commands, so both surfaces
 * dispatch the same domain event exactly once and only on an actual change —
 * granting a user who already has access, or revoking one who has none, is a
 * no-op and must not fire.
 */
final class AdminAccess
{
    public static function grant(int | string $userId): void
    {
        $profile = CmsUserProfile::query()->firstOrCreate(['user_id' => $userId]);

        if ($profile->wasRecentlyCreated) {
            Event::dispatch(new CmsAccessGranted($userId));
        }
    }

    public static function revoke(int | string $userId): void
    {
        $deleted = CmsUserProfile::query()->where('user_id', $userId)->delete();

        if ($deleted > 0) {
            Event::dispatch(new CmsAccessRevoked($userId));
        }
    }
}
