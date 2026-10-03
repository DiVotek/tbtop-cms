<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\TypeBlock;
use Tbtop\Cms\Page\Types\BasePageType;

/** A page subtype with one general block (Simple, its fields on the General tab) and one content block (Hero). */
class ContactsPageType extends BasePageType
{
    public function key(): string
    {
        return 'contacts';
    }

    #[\Override]
    public function base(): string
    {
        return 'page';
    }

    /** @return list<TypeBlock> */
    #[\Override]
    public function blocks(): array
    {
        return [TypeBlock::general('Simple'), TypeBlock::content('Hero')];
    }
}
