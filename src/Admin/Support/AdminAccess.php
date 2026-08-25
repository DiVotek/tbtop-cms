<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tbtop\Cms\Admin\Events\CmsAccessGranted;
use Tbtop\Cms\Admin\Events\CmsAccessRevoked;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Audit\AuditContext;

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
        DB::transaction(function () use ($userId): void {
            $profile = CmsUserProfile::query()->firstOrCreate(['user_id' => $userId]);

            if (! $profile->wasRecentlyCreated) {
                return;
            }

            self::markFirstOwner($profile);

            Event::dispatch(new CmsAccessGranted($userId));
            self::logAccess($userId, 'access_granted', (bool) $profile->is_owner);
        });
    }

    /**
     * The first admin of a fresh install becomes the owner automatically —
     * no installer coupling needed. Protection only, never an extra ability.
     *
     * One conditional UPDATE rather than a check-then-write: two concurrent
     * first grants must not both observe "no owner yet" and mark two owners.
     * The no-owner guard lives in the statement itself, so statement-level
     * atomicity makes a second concurrent marker a no-op. The subquery is
     * wrapped in a derived table because MySQL rejects reading the updated
     * table directly inside its own UPDATE.
     */
    private static function markFirstOwner(CmsUserProfile $profile): void
    {
        $table = $profile->getTable();

        $marked = DB::table($table)
            ->where($profile->getKeyName(), $profile->getKey())
            ->whereRaw("not exists (select 1 from (select 1 from {$table} where is_owner = 1) as existing_owner)")
            ->update(['is_owner' => true]);

        if ($marked > 0) {
            $profile->setAttribute('is_owner', true)->syncOriginal();
        }
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
            self::logAccess($userId, 'access_revoked', false);
        }
    }

    public static function isOwner(int | string $userId): bool
    {
        return CmsUserProfile::query()->where('user_id', $userId)->where('is_owner', true)->exists();
    }

    private static function logAccess(int | string $userId, string $event, bool $isOwner): void
    {
        $user = self::user($userId);
        if ($user === null) {
            return;
        }

        $hasAccess = $event === 'access_granted';
        app(AuditContext::class)->log(
            $user,
            $event,
            self::label($user),
            [
                'attributes' => ['has_access' => $hasAccess, 'is_owner' => $isOwner],
                'old' => ['has_access' => ! $hasAccess, 'is_owner' => false],
            ],
            ['source' => CurrentAdmin::canAccess() ? 'admin' : 'console'],
            allowAnonymous: true,
        );
    }

    private static function user(int | string $userId): ?Model
    {
        /** @var class-string<Model> $model */
        $model = config('tbtop-cms.admin.model', 'App\\Models\\User');

        return $model::query()->find($userId);
    }

    private static function label(Model $user): string
    {
        $name = (string) $user->getAttribute('name');

        return $name !== '' ? $name : (string) $user->getAttribute('email');
    }
}
