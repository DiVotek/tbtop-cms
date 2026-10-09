<?php

declare(strict_types=1);

namespace Tbtop\Cms\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Throwable;

/**
 * Optimistic-lock writes on a model's `lock_version`, each one SQL statement
 * separate from the model's own UPDATE. The model then holds the new version
 * as original, so its UPDATE never writes the column.
 */
final class LockVersion
{
    /**
     * The form path: claims the row (compare and bump in one conditional
     * UPDATE) when $write, else only compares. The SoftDeletes scope applies,
     * so a missing or trashed row is a 404; any other mismatch is $conflict().
     *
     * @param  Closure(): Throwable  $conflict
     */
    public static function take(Model $model, int $expected, bool $write, Closure $conflict): void
    {
        $matched = $write
            ? self::scoped($model)->where('lock_version', $expected)->increment('lock_version') === 1
            : self::stored($model) === $expected;

        if ($matched) {
            self::sync($model, $write ? $expected + 1 : $expected);

            return;
        }

        if (self::stored($model) === null) {
            throw (new ModelNotFoundException)->setModel($model::class, [$model->getKey()]);
        }

        throw $conflict();
    }

    /** Non-form writers: a relative bump with no compare, trashed rows included (untrash bumps too). */
    public static function bump(Model $model): void
    {
        $model->newQueryWithoutScopes()->whereKey($model->getKey())->toBase()->increment('lock_version');

        $version = $model->newQueryWithoutScopes()->whereKey($model->getKey())->toBase()->value('lock_version');

        if ($version !== null) {
            self::sync($model, (int) $version);
        }
    }

    private static function stored(Model $model): ?int
    {
        $version = self::scoped($model)->value('lock_version');

        return $version === null ? null : (int) $version;
    }

    private static function scoped(Model $model): Builder
    {
        return $model->newQuery()->whereKey($model->getKey())->toBase();
    }

    private static function sync(Model $model, int $version): void
    {
        $model->setAttribute('lock_version', $version);
        $model->syncOriginalAttribute('lock_version');
    }
}
