<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Block\HasFieldLabels;
use Tbtop\Cms\Block\SubmitContext;

/** A form whose field label is editor copy: translatable block data, not a lang key. */
class LabelledFormBlock extends Block implements HandlesFormSubmission, HasFieldLabels
{
    public function kind(): string
    {
        return 'Labelled';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [$s->text('valueLabel')->translatable()];
    }

    /**
     * @param  array<string, mixed>  $blockData
     * @return array<string, mixed>
     */
    public function submitRules(array $blockData, SubmitContext $ctx): array
    {
        return ['value' => 'required'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $blockData
     */
    public function handleSubmit(array $payload, array $blockData, SubmitContext $ctx): void {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function fieldLabels(string $locale, array $input): array
    {
        return ['value' => is_string($input['valueLabel'] ?? null) ? $input['valueLabel'] : ''];
    }
}
