<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Collection\Events\EntriesUpdated;

/**
 * A bulk update skips model events, and the admin table's drag reorder is
 * one: it writes sort_order through the table's query. Reported here so the
 * site's cached collection follows the new order.
 *
 * @template TModel of Entry
 *
 * @extends Builder<TModel>
 */
class EntryBuilder extends Builder
{
    /** @param  array<array-key, mixed>  $values */
    #[\Override]
    public function update(array $values): int
    {
        $count = parent::update($values);

        if ($count > 0) {
            DB::afterCommit(static fn () => event(new EntriesUpdated));
        }

        return $count;
    }
}
