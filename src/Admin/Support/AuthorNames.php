<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves author display names for a batch of host-app user ids, without a
 * per-row query. Extracted from PageEditRevisionsAction (revisions used to
 * carry the only copy of this lookup) once author_tracking needed the exact
 * same name-then-email-then-null resolution for created_by/updated_by across
 * the status bar and the Pages/Templates/Redirects list tables.
 *
 * The host user model is read from config('tbtop-cms.admin.model') every
 * call rather than cached — the same pattern CurrentAdmin and UsersPage use,
 * since a host can only ever configure one user model per request anyway.
 */
final class AuthorNames
{
    /**
     * One whereIn() query for every id in $userIds, rather than one query per
     * row — callers render at most a handful of rows (a revisions panel, a
     * status bar), but there's no reason to pay N queries for something a
     * single query answers.
     *
     * @param  iterable<int|string|null>  $userIds
     * @return Collection<int|string, Model> keyed by user id
     */
    public static function lookup(iterable $userIds): Collection
    {
        $ids = collect($userIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            /** @var Collection<int|string, Model> */
            return new Collection;
        }

        /** @var class-string<Model> $userModel */
        $userModel = config('tbtop-cms.admin.model', 'App\\Models\\User');

        return $userModel::query()->whereIn('id', $ids)->get()->keyBy(
            static fn (Model $user): int | string => $user->getKey(),
        );
    }

    /**
     * name → email → null, matching the resolution every author-facing UI in
     * this package uses (revisions panel, status bar). null means "no user
     * found for this id" (already deleted, or the id itself was null) — every
     * caller decides its own fallback text (e.g. an em dash) rather than this
     * class hardcoding one, since a status bar and a table column render null
     * differently.
     *
     * @param  Collection<int|string, Model>  $authors  As returned by lookup().
     */
    public static function label(int | string | null $userId, Collection $authors): ?string
    {
        $user = $userId !== null ? $authors->get($userId) : null;

        if ($user === null) {
            return null;
        }

        $name = isset($user->name) ? (string) $user->name : '';
        if ($name !== '') {
            return $name;
        }

        $email = isset($user->email) ? (string) $user->email : '';

        return $email !== '' ? $email : null;
    }

    /**
     * A correlated subselect resolving $ownerColumn (e.g. updated_by) on
     * $ownerTable to the referenced host user's name, falling back to their
     * email, or null when $ownerColumn is null or the user no longer exists —
     * the same name-then-email-then-null resolution as label(), expressed as
     * SQL instead of PHP so a list table's query stays one query (mirrors
     * UsersPage::query()'s cms_role correlated subselect over
     * cms_user_profiles: same "compute it in the query, project it as a
     * plain column" shape, just against the host user table instead).
     *
     * COALESCE(NULLIF(name, ''), email) rather than a driver-specific
     * function: NULLIF and COALESCE are both plain ANSI SQL, so this reads
     * identically on sqlite (tests), mysql, and postgres (hosts) with no
     * per-engine branching.
     */
    public static function subselect(string $ownerTable, string $ownerColumn): QueryBuilder
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('tbtop-cms.admin.model', 'App\\Models\\User');
        $userTable = (new $userModel)->getTable();

        return DB::table($userTable)
            ->selectRaw("COALESCE(NULLIF({$userTable}.name, ''), {$userTable}.email)")
            ->whereColumn($userTable . '.id', $ownerTable . '.' . $ownerColumn)
            ->limit(1);
    }
}
