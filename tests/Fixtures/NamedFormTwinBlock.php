<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

/** Declares the same form name as NamedFormBlock under another kind. */
class NamedFormTwinBlock extends NamedFormBlock
{
    #[\Override]
    public function kind(): string
    {
        return 'ServiceOrderTwin';
    }

    #[\Override]
    public function formName(): string
    {
        return 'ServiceOrder';
    }
}
