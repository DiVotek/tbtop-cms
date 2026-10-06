<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

/** One block's extracted props fragment, ready for the emitter. */
final class BlockShape
{
    /**
     * @param  array<string, true>  $usedAliases  alias names this block's fragment references, in first-use order
     * @param  list<string>  $warnings  props typed as unknown, one line each for the command to print
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $propsFragment,
        public readonly bool $isTyped,
        public readonly array $usedAliases,
        public readonly ?string $leadingComment = null,
        public readonly array $warnings = [],
    ) {}
}
