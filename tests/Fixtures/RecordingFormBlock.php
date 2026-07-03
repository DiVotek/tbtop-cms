<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Block\SubmitContext;

class RecordingFormBlock extends Block implements HandlesFormSubmission
{
    public static ?string $locale = null;

    /** @var array<string, mixed> */
    public static array $data = [];

    public function kind(): string
    {
        return 'Recording';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [$s->text('label')->translatable(), $s->text('config')];
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
        self::$locale = $ctx->locale;
        self::$data = $blockData;
    }
}
