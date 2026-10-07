<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * A subtype whose base() names no registered type, or names another
 * subtype, can never be picked: no page can hold it in `subtype`. Reported
 * here rather than thrown by PageTypeRegistry, which boots lazily on the
 * first page read — a throw there would take the site, the admin and
 * `migrate` down for one misdeclared class.
 */
final class PageSubtypeBasesCheck implements HealthCheck
{
    public function __construct(private readonly PageTypeRegistry $registry) {}

    public function id(): string
    {
        return 'page_subtype_bases';
    }

    public function run(): HealthCheckResult
    {
        $broken = [];

        foreach ($this->registry->all() as $key => $type) {
            $base = $type->base();

            if ($base === $key) {
                continue;
            }

            if (! $this->registry->has($base) || $this->registry->get($base)->base() !== $base) {
                $broken[] = "{$key} → {$base}";
            }
        }

        if ($broken === []) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Ok,
                labelKey: 'tbtop-cms::health.checks.page_subtype_bases.label',
                messageKey: 'tbtop-cms::health.checks.page_subtype_bases.ok',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Warning,
            labelKey: 'tbtop-cms::health.checks.page_subtype_bases.label',
            messageKey: 'tbtop-cms::health.checks.page_subtype_bases.warning',
            messageReplace: ['subtypes' => implode('; ', $broken)],
            hintKey: 'tbtop-cms::health.checks.page_subtype_bases.hints.warning',
        );
    }
}
