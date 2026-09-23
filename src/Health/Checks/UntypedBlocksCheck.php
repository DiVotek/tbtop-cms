<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\BlockTypes\BlockShapeExtractor;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;
use Throwable;

/**
 * Surfaces the same untyped-block fallback `cms:blocks:types` degrades to
 * as a proactive warning, before a host notices its generated catalog has a
 * permissive `z.record()` hole for a block it forgot to type.
 * A malformed docblock the command would hard-error on is left to the
 * command itself — this check only distinguishes typed from untyped, it
 * never fails the run.
 */
final class UntypedBlocksCheck implements HealthCheck
{
    public function __construct(private readonly BlockRegistry $registry) {}

    public function id(): string
    {
        return 'untyped_blocks';
    }

    public function run(): HealthCheckResult
    {
        $untyped = $this->untypedKinds();

        if ($untyped === []) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Ok,
                labelKey: 'tbtop-cms::health.checks.untyped_blocks.label',
                messageKey: 'tbtop-cms::health.checks.untyped_blocks.ok',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Warning,
            labelKey: 'tbtop-cms::health.checks.untyped_blocks.label',
            messageKey: 'tbtop-cms::health.checks.untyped_blocks.warning',
            messageReplace: ['kinds' => implode(', ', $untyped)],
            hintKey: 'tbtop-cms::health.checks.untyped_blocks.hints.warning',
        );
    }

    /** @return list<string> */
    private function untypedKinds(): array
    {
        $extractor = new BlockShapeExtractor;
        $untyped = [];

        foreach ($this->registry->all() as $block) {
            if (! self::isTypedOrMalformed($extractor, $block)) {
                $untyped[] = $block->kind();
            }
        }

        return $untyped;
    }

    /** Malformed docblocks are cms:blocks:types's error to report; a health check never throws. */
    private static function isTypedOrMalformed(BlockShapeExtractor $extractor, Block $block): bool
    {
        try {
            return $extractor->extract($block)->isTyped;
        } catch (Throwable) {
            return true;
        }
    }
}
