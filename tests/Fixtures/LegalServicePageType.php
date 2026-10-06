<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Types\ArticleType;

/** An article subtype that lists the specialists referencing it through their `services` field. */
class LegalServicePageType extends ArticleType
{
    #[\Override]
    public function key(): string
    {
        return 'legal_service';
    }

    #[\Override]
    public function base(): string
    {
        return 'article';
    }

    /** @return array<string, array{field: string, from?: string}> */
    #[\Override]
    public function backReferences(): array
    {
        return ['specialists' => ['field' => 'services', 'from' => 'specialist']];
    }
}
