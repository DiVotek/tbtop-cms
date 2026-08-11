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
        $wasEmpty = ! CmsUserProfile::query()->exists();
        $profile = CmsUserProfile::query()->firstOrCreate(['user_id' => $userId]);

        if (! $profile->wasRecentlyCreated) {
            return;
        }

        // The first profile row of a fresh install becomes the owner
        // automatically — no installer coupling needed. Protection only,
        // never an extra ability: see CmsUserProfile::$casts.
        if ($wasEmpty) {
            $profile->forceFill(['is_owner' => true])->save();
        }

        Event::dispatch(new CmsAccessGranted($userId));
    }

    /**
     * The owner row is never deleted — `is_owner = false` in the delete
     * query makes that guard hold by construction for every caller (page,
     * commands, future code), not just the ones that remember to check first.
     */
    public static function revoke(int | string $userId): void
    {
        $deleted = CmsUserProfile::query()->where('user_id', $userId)->where('is_owner', false)->delete();

        if ($deleted > 0) {
            Event::dispatch(new CmsAccessRevoked($userId));
        }
    }

    public static function isOwner(int | string $userId): bool
    {
        return CmsUserProfile::query()->where('user_id', $userId)->where('is_owner', true)->exists();
    }
}
