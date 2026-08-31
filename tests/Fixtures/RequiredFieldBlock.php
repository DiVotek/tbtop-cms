<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;

/**
 * Registered block for LayoutEditPage's per-block-tab tests: one required
 * field, so validation-gate tests (active block with an empty required field
 * rejected, inactive block with the same field left empty saves fine) have
 * something to fail on. The custom label() (differing from the kind's
 * headline-cased fallback, "Required Field") also lets tab-label tests tell
 * "used the registered label" apart from "fell back to the raw kind".
 */
class RequiredFieldBlock extends Block
{
    public function kind(): string
    {
        return 'RequiredField';
    }

    #[\Override]
    public function label(): string
    {
        return 'Needs a value';
    }

    /** @return list<Field> */
    public function form(S $s): array
    {
        return [$s->text('value')->label('Value')->required()];
    }
}
