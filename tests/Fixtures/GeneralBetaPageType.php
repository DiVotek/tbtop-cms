<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\TypeBlock;
use Tbtop\Cms\Page\Types\BasePageType;

/** Declares the Discovery fixture's Beta block — placeable on every page — as a general block, for the registry-emitter test. */
class GeneralBetaPageType extends BasePageType
{
    public function key(): string
    {
        return 'general-beta';
    }

    /** @return list<TypeBlock> */
    #[\Override]
    public function blocks(): array
    {
        return [TypeBlock::general('Beta')];
    }
}
