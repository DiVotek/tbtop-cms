<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Block\SubmitContext;
use Tbtop\Cms\Form\HasForm;

/** A form a page type template renders: reachable by name, placed nowhere. */
class NamedFormBlock extends Block implements HandlesFormSubmission
{
    use HasForm;

    /** @var array<string, mixed>|null */
    public static ?array $data = null;

    public function kind(): string
    {
        return 'ServiceOrder';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $blockData
     * @return array<string, mixed>
     */
    public function submitRules(array $blockData, SubmitContext $ctx): array
    {
        return ['phone' => 'required'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $blockData
     */
    public function handleSubmit(array $payload, array $blockData, SubmitContext $ctx): void
    {
        self::$data = $blockData;
    }
}
