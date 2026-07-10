<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;

/**
 * Minimal registered block for PageEditPage's per-block-tab tests: a plain
 * text field and a textarea, neither required, so save-round-trip tests
 * don't have to fight validation to exercise the envelope-folding logic.
 */
class SimpleBlock extends Block
{
    public function kind(): string
    {
        return 'Simple';
    }

    /** @return list<Field> */
    public function form(S $s): array
    {
        return [
            $s->text('heading')->label('Heading'),
            $s->textarea('body')->label('Body'),
        ];
    }
}
