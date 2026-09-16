<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Types\BasePageType;

/** A content-only page type offering more than one schema key, for schema-selection tests. */
class TwoSchemaPageType extends BasePageType
{
    public function key(): string
    {
        return 'two_schema';
    }

    /** @return non-empty-list<string> */
    #[\Override]
    public function schemaTypes(): array
    {
        return ['WebPage', 'AboutPage'];
    }
}
