<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Collection\Collection;

/** A host collection with no `pages` field: nothing to relate its entries by. */
class TeamCollection extends Collection
{
    public function key(): string
    {
        return 'team';
    }

    public function label(): string
    {
        return 'Team';
    }

    public function fields(S $s): array
    {
        return [
            $s->text('name')->label('Name')->required(),
            $s->text('role')->label('Role'),
        ];
    }
}
