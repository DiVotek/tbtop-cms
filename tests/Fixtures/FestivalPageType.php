<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Types\BasePageType;

/** A subtype of EventPageType whose `venue` back-reference shares a name with the base's `venue` field. */
class FestivalPageType extends BasePageType
{
    public function key(): string
    {
        return 'festival';
    }

    #[\Override]
    public function base(): string
    {
        return 'event';
    }

    /** @return array<string, array{field: string, from?: string}> */
    #[\Override]
    public function backReferences(): array
    {
        return ['venue' => ['field' => 'festivals'], 'lineup' => ['field' => 'festivals']];
    }
}
