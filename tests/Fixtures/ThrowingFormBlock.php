<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use RuntimeException;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Block\SubmitContext;

class ThrowingFormBlock extends Block implements HandlesFormSubmission
{
    public function kind(): string
    {
        return 'Throwing';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [$s->text('label')];
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
    public function handleSubmit(array $payload, array $blockData, SubmitContext $ctx): void
    {
        throw new RuntimeException('handler boom');
    }
}
