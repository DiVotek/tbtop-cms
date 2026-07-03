<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Commands\Concerns;

use Illuminate\Database\Eloquent\Model;
use Tbtop\Cms\Admin\Contracts\CmsAdmin;
use Tbtop\Cms\Admin\Models\CmsUserProfile;

trait InteractsWithAdminUsers
{
    /** @return class-string<Model> */
    protected function adminModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config('tbtop-cms.admin.model', 'App\\Models\\User');

        return $model;
    }

    protected function findUser(string $email): ?Model
    {
        return $this->adminModel()::query()->where('email', $email)->first();
    }

    protected function grantAccess(Model $user): void
    {
        CmsUserProfile::query()->firstOrCreate(['user_id' => $user->getKey()]);
    }

    protected function revokeAccess(Model $user): void
    {
        CmsUserProfile::query()->where('user_id', $user->getKey())->delete();
    }

    protected function overridesProfileAccess(Model $user): bool
    {
        return $user instanceof CmsAdmin;
    }
}
