<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Tbtop\Cms\Admin\Contracts\CmsAdmin;
use Tbtop\Cms\Admin\Enums\CmsRole;
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
        $userId = auth()->guard(self::guard())->user()?->getAuthIdentifier();

        return CmsUserProfile::query()->firstOrNew(['user_id' => $userId]);
    }

    /**
     * The signed-in user's effective cms role, falling back to Editor
     * (least privilege) when no role can be resolved — callers of role()
     * are already behind EnsureCmsAdmin, so "no role" here only means a
     * stale session edge, not an access decision.
     */
    public static function role(): CmsRole
    {
        $user = auth()->guard(self::guard())->user();

        return ($user !== null ? self::roleOf($user) : null) ?? CmsRole::Editor;
    }

    /**
     * Resolve a user's cms role, or null when they have no admin access.
     * Precedence mirrors allows(): a CmsAdmin implementation decides access
     * for itself — when it grants access, a CmsUserProfile row (if any)
     * refines the role, and without one the user is an implicit
     * superadministrator (before roles existed, contract-based access meant
     * full access; hosts downgrade by creating a profile row). Non-contract
     * users get a role only from an existing profile row.
     */
    public static function roleOf(Authenticatable $user): ?CmsRole
    {
        if ($user instanceof CmsAdmin) {
            if (! $user->canAccessCmsAdmin()) {
                return null;
            }

            return self::profileRoleOf($user) ?? CmsRole::SuperAdministrator;
        }

        return self::profileRoleOf($user);
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

    private static function profileRoleOf(Authenticatable $user): ?CmsRole
    {
        return CmsUserProfile::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->first()
            ?->role;
    }
}
