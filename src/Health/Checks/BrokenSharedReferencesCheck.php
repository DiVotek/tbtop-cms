<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Block\BlockRowProblem;
use Tbtop\Cms\Health\Checks\Concerns\CountsProblemRows;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * Rows whose `shared` pointer leads nowhere: the shared block is gone or
 * now has another kind. The site skips each one without a trace; the editor
 * lists it under Problem blocks, so this is the operator's count of them.
 * A row whose kind is unregistered is counted by UnregisteredBlockRowsCheck.
 */
final class BrokenSharedReferencesCheck implements HealthCheck
{
    use CountsProblemRows;

    public function id(): string
    {
        return 'broken_shared_references';
    }

    public function run(): HealthCheckResult
    {
        $broken = $this->problemRowCounts([BlockRowProblem::SharedMissing, BlockRowProblem::SharedKindMismatch]);

        if ($broken === []) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Ok,
                labelKey: 'tbtop-cms::health.checks.broken_shared_references.label',
                messageKey: 'tbtop-cms::health.checks.broken_shared_references.ok',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Warning,
            labelKey: 'tbtop-cms::health.checks.broken_shared_references.label',
            messageKey: 'tbtop-cms::health.checks.broken_shared_references.warning',
            messageReplace: ['containers' => implode('; ', $broken)],
            hintKey: 'tbtop-cms::health.checks.broken_shared_references.hints.warning',
        );
    }
}
