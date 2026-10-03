<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\Discovery\Concerns;

trait Describes
{
    public function description(): ?string
    {
        return 'A "quoted" fixture block';
    }
}
