<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Tbtop\Cms\Admin\Contracts\CmsAdmin;
use Tbtop\Cms\Admin\Models\CmsUserProfile;

/**
 * Every host-app user with an admin seat, for fan-out delivery (the bell
 * notification on a new submission) rather than access control. Recipients
 * resolve through the same per-user rule as panel access
 * ({@see CurrentAdmin::allows()}): a user model implementing the CmsAdmin
 * contract decides for itself — enumerated with a chunked scan, since only
 * the model can answer — while any other model qualifies via its
 * {@see CmsUserProfile} row.
 */
final class AdminUsers
{
    /** @return Collection<int, Model> */
    public static function all(): Collection
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('tbtop-cms.admin.model', 'App\\Models\\User');

        if (is_a($userModel, CmsAdmin::class, true)) {
            $admins = new Collection;
            $userModel::query()->chunkById(500, function (Collection $chunk) use ($admins): void {
                foreach ($chunk as $user) {
                    if ($user instanceof Authenticatable && CurrentAdmin::allows($user)) {
                        $admins->push($user);
                    }
                }
            });

            return $admins;
        }

        $ids = CmsUserProfile::query()->pluck('user_id');

        if ($ids->isEmpty()) {
            return new Collection;
        }

        return $userModel::query()->whereKey($ids)->get();
    }
}
