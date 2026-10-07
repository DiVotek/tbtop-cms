<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Types\BasePageType;

/** A subtype whose base() names a type nothing registers. */
class OrphanSubtypePageType extends BasePageType
{
    public function key(): string
    {
        return 'orphan';
    }

    #[\Override]
    public function base(): string
    {
        return 'ghost';
    }
}
