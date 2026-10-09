<?php

declare(strict_types=1);

namespace Tbtop\Cms\Support;

use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One transaction around a save and its side effects. Only the outermost
 * transaction maps a lock-busy, lock-wait-timeout or deadlock error to
 * $conflict: below it the error must reach the boundary that owns the rollback.
 */
final class LockedSave
{
    use DetectsConcurrencyErrors;

    /**
     * @template T
     *
     * @param  Closure(): T  $write
     * @param  Closure(Throwable): Throwable  $conflict
     * @return T
     */
    public static function run(Closure $write, Closure $conflict): mixed
    {
        $outermost = DB::transactionLevel() === 0;

        try {
            return DB::transaction($write);
        } catch (Throwable $e) {
            if ($outermost && (new self)->causedByConcurrencyError($e)) {
                throw $conflict($e);
            }

            throw $e;
        }
    }
}
