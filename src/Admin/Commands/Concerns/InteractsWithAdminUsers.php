<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Commands\Concerns;

use Illuminate\Database\Eloquent\Model;
use Tbtop\Cms\Admin\Contracts\CmsAdmin;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Support\AdminAccess;

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
        AdminAccess::grant(self::userId($user));
    }

    protected function revokeAccess(Model $user): void
    {
        AdminAccess::revoke(self::userId($user));
    }

    protected function isOwner(Model $user): bool
    {
        return AdminAccess::isOwner(self::userId($user));
    }

    protected function hasProfile(Model $user): bool
    {
        return CmsUserProfile::query()->where('user_id', self::userId($user))->exists();
    }

    private static function userId(Model $user): int | string
    {
        $id = $user->getKey();

        return is_int($id) ? $id : (string) $id;
    }

    protected function overridesProfileAccess(Model $user): bool
    {
        return $user instanceof CmsAdmin;
    }
}
