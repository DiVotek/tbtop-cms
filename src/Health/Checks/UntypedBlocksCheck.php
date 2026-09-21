<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\BlockTypes\BlockShapeExtractor;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;
use Throwable;

/**
 * Surfaces the same untyped-block fallback `cms:blocks:types` degrades to
 * (decision 4) as a proactive warning, before a host notices its generated
 * catalog has a permissive `z.record()` hole for a block it forgot to type.
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
            // A block whose docblock is malformed enough to make cms:blocks:types
            // itself error is not this check's concern — cms:doctor must
            // stay non-throwing (see HealthCheck's own interface docblock),
            // and the command already reports that failure loudly on its own run.
            try {
                if (! $extractor->extract($block)->isTyped) {
                    $untyped[] = $block->kind();
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $untyped;
    }
}
