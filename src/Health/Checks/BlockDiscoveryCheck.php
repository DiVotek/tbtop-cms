<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Block\BlockDiscovery;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * A wrong namespace, a missing folder or a list instead of a map in
 * `tbtop-cms.blocks.discover` registers no block and raises nothing: the
 * editor just has fewer blocks. A warning, not an error — the site still
 * renders what it has.
 */
final class BlockDiscoveryCheck implements HealthCheck
{
    public function id(): string
    {
        return 'block_discovery';
    }

    public function run(): HealthCheckResult
    {
        ['empty' => $empty, 'malformed' => $malformed] = BlockDiscovery::problems(config('tbtop-cms.blocks.discover', []));

        if ($empty === [] && $malformed === []) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Ok,
                labelKey: 'tbtop-cms::health.checks.block_discovery.label',
                messageKey: 'tbtop-cms::health.checks.block_discovery.ok',
            );
        }

        $messageKey = match (true) {
            $empty === [] => 'malformed',
            $malformed === [] => 'empty',
            default => 'both',
        };

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Warning,
            labelKey: 'tbtop-cms::health.checks.block_discovery.label',
            messageKey: "tbtop-cms::health.checks.block_discovery.{$messageKey}",
            messageReplace: ['empty' => implode('; ', $empty), 'malformed' => implode('; ', $malformed)],
            hintKey: 'tbtop-cms::health.checks.block_discovery.hints.warning',
        );
    }
}
