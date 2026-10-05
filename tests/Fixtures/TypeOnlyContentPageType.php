<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\TypeBlock;
use Tbtop\Cms\Page\Types\BasePageType;

/** Carries FieldsOnly — placeable nowhere — as a content block: only this type's pages render it. */
class TypeOnlyContentPageType extends BasePageType
{
    public function key(): string
    {
        return 'type-only-content';
    }

    /** @return list<TypeBlock> */
    #[\Override]
    public function blocks(): array
    {
        return [TypeBlock::content('FieldsOnly')];
    }
}
