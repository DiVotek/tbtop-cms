<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Types\BasePageType;

/** A subtype of the built-in page, with no fields of its own. */
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
}
