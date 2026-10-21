<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Tbtop\Cms\Admin\Contracts\CmsAdmin;
use Tbtop\Cms\Admin\Models\CmsUserProfile;

/**
 * The signed-in admin behind the configured guard: the host app's
 * user model plus the cms-owned per-user profile row.
 */
final class CurrentAdmin
{
    public static function guard(): string
    {
        return (string) config('tbtop-cms.admin.guard', 'web');
    }

    public static function user(): ?Model
    {
        $user = auth()->guard(self::guard())->user();

        return $user instanceof Model ? $user : null;
    }

    public static function email(): string
    {
        $user = self::user();

        return $user !== null && isset($user->email) ? (string) $user->email : '';
    }

    public static function profile(): CmsUserProfile
    {
        $user = auth()->guard(self::guard())->user();

        return CmsUserProfile::query()->firstOrNew(['user_id' => $user?->getAuthIdentifier()]);
    }

    /**
     * Whether the currently signed-in guard user may enter the cms admin.
     * Being authenticated on the guard is NOT enough — the guard (default
     * `web`) is often the host app's regular end-user guard.
     */
    public static function canAccess(): bool
    {
        $user = auth()->guard(self::guard())->user();

        return $user !== null && self::allows($user);
    }

    /** Whether the currently signed-in guard user is the cms owner. */
    public static function isOwner(): bool
    {
        $userId = auth()->guard(self::guard())->id();

        return $userId !== null && AdminAccess::isOwner($userId);
    }

    /**
     * The per-user admin-access rule shared by EnsureCmsAdmin (panel
     * routes) and draft preview (frontend): a CmsAdmin implementation
     * decides for itself; any other user model qualifies only via an
     * existing CmsUserProfile row.
     */
    public static function allows(Authenticatable $user): bool
    {
        if ($user instanceof CmsAdmin) {
            return $user->canAccessCmsAdmin();
        }

        return CmsUserProfile::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->exists();
    }
}
