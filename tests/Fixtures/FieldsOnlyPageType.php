<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\TypeBlock;
use Tbtop\Cms\Page\Types\BasePageType;

/** Two general blocks sharing the `heading` key: Simple (placeable elsewhere) first, FieldsOnly (placeable nowhere) after it. */
class FieldsOnlyPageType extends BasePageType
{
    public function key(): string
    {
        return 'fields-only';
    }

    /** @return list<TypeBlock> */
    #[\Override]
    public function blocks(): array
    {
        return [TypeBlock::general('Simple'), TypeBlock::general('FieldsOnly')];
    }
}
