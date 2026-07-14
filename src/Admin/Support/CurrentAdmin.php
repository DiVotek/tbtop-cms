<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Support;

use Illuminate\Database\Eloquent\Model;
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
}
