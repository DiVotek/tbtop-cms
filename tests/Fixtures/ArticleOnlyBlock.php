<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;

class ArticleOnlyBlock extends Block
{
    public function kind(): string
    {
        return 'ArticleOnly';
    }

    /** @return list<Field> */
    public function form(S $s): array
    {
        return [$s->text('label')->label('Label')];
    }

    /** @return list<string> */
    #[\Override]
    public function placements(): array
    {
        return ['article'];
    }
}
