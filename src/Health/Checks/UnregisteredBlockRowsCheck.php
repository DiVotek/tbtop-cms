<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Block\BlockRowProblem;
use Tbtop\Cms\Health\Checks\Concerns\CountsProblemRows;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * Rows whose kind no block class registers any more (the class was deleted
 * or renamed). The site skips each one without a trace and keeps its data;
 * the editor lists it under Problem blocks, so this is the operator's count.
 */
final class UnregisteredBlockRowsCheck implements HealthCheck
{
    use CountsProblemRows;

    public function id(): string
    {
        return 'unregistered_block_rows';
    }

    public function run(): HealthCheckResult
    {
        $rows = $this->problemRowCounts([BlockRowProblem::Unregistered]);

        if ($rows === []) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Ok,
                labelKey: 'tbtop-cms::health.checks.unregistered_block_rows.label',
                messageKey: 'tbtop-cms::health.checks.unregistered_block_rows.ok',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Warning,
            labelKey: 'tbtop-cms::health.checks.unregistered_block_rows.label',
            messageKey: 'tbtop-cms::health.checks.unregistered_block_rows.warning',
            messageReplace: ['containers' => implode('; ', $rows)],
            hintKey: 'tbtop-cms::health.checks.unregistered_block_rows.hints.warning',
        );
    }
}
