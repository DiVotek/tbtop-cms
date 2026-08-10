<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Tbtop\Cms\Admin\Models\CmsUserProfile;

/**
 * Every host-app user with an admin seat, for fan-out delivery (the bell
 * notification on a new submission) rather than access control. Scoped to
 * users with a {@see CmsUserProfile} row — the population cms itself owns
 * and can enumerate. A host user granted access only via the CmsAdmin
 * contract (no profile row — see CurrentAdmin::allows()) is not reachable
 * here: cms has no way to ask an arbitrary host user model "would
 * canAccessCmsAdmin() return true for you" without iterating every row.
 */
final class AdminUsers
{
    /** @return Collection<int, Model> */
    public static function all(): Collection
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('tbtop-cms.admin.model', 'App\\Models\\User');

        $ids = CmsUserProfile::query()->pluck('user_id');

        if ($ids->isEmpty()) {
            return new Collection;
        }

        return $userModel::query()->whereIn('id', $ids)->get();
    }
}
