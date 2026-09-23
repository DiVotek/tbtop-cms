<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\BlockTypes;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Block\SubmitContext;

/** HandlesFormSubmission block that declares the injected action key in quotes. */
class QuotedActionFormBlock extends Block implements HandlesFormSubmission
{
    public function kind(): string
    {
        return 'QuotedActionForm';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{'action': string}
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return ['action' => ''];
    }

    /**
     * @param  array<string, mixed>  $blockData
     * @return array<string, mixed>
     */
    public function submitRules(array $blockData, SubmitContext $ctx): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $blockData
     */
    public function handleSubmit(array $payload, array $blockData, SubmitContext $ctx): void {}
}
