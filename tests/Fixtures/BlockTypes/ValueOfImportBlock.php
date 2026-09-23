<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\BlockTypes;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Tests\Fixtures\BlockTypes\Sources\StatusSource as Statuses;

/**
 * @phpstan-type Status value-of<Statuses::ALL>
 */
class ValueOfImportBlock extends Block
{
    public function kind(): string
    {
        return 'ValueOfImport';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{kind: value-of<ValueOfConstFixture::KINDS>, status: Status}
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return ['kind' => 'a', 'status' => 'draft'];
    }
}
