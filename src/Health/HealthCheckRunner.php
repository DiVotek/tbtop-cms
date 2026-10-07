<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health;

use Tbtop\Cms\Health\Checks\ActivityLogTableCheck;
use Tbtop\Cms\Health\Checks\AgentDocsPointerCheck;
use Tbtop\Cms\Health\Checks\AppUrlCheck;
use Tbtop\Cms\Health\Checks\BlockDiscoveryCheck;
use Tbtop\Cms\Health\Checks\BrokenSharedReferencesCheck;
use Tbtop\Cms\Health\Checks\DefaultOwnerAndLanguageCheck;
use Tbtop\Cms\Health\Checks\InertiaRootViewCheck;
use Tbtop\Cms\Health\Checks\JsWiringCheck;
use Tbtop\Cms\Health\Checks\MediaDiskWritableCheck;
use Tbtop\Cms\Health\Checks\NotificationsConfiguredCheck;
use Tbtop\Cms\Health\Checks\PackageVersionsMatchCheck;
use Tbtop\Cms\Health\Checks\PageSubtypeBasesCheck;
use Tbtop\Cms\Health\Checks\PageTypePairsCheck;
use Tbtop\Cms\Health\Checks\RobotsTxtCheck;
use Tbtop\Cms\Health\Checks\RoutesRegisteredCheck;
use Tbtop\Cms\Health\Checks\SchedulerHeartbeatCheck;
use Tbtop\Cms\Health\Checks\SessionDriverCheck;
use Tbtop\Cms\Health\Checks\UnknownSchemaTypesCheck;
use Tbtop\Cms\Health\Checks\UnregisteredBlockRowsCheck;
use Tbtop\Cms\Health\Checks\UntypedBlocksCheck;
use Throwable;

/**
 * Executes every registered check and folds the results into a report.
 * Each check runs isolated: a throwing check becomes an Error result
 * naming the exception rather than aborting the whole report, since a bug
 * in one check must never hide the other nine results from an operator
 * running `cms:doctor` to diagnose a broken install.
 */
final class HealthCheckRunner
{
    /** @return list<class-string<HealthCheck>> */
    public static function defaultChecks(): array
    {
        return [
            RoutesRegisteredCheck::class,
            ActivityLogTableCheck::class,
            InertiaRootViewCheck::class,
            JsWiringCheck::class,
            PackageVersionsMatchCheck::class,
            RobotsTxtCheck::class,
            MediaDiskWritableCheck::class,
            NotificationsConfiguredCheck::class,
            SchedulerHeartbeatCheck::class,
            AppUrlCheck::class,
            DefaultOwnerAndLanguageCheck::class,
            SessionDriverCheck::class,
            AgentDocsPointerCheck::class,
            UntypedBlocksCheck::class,
            BlockDiscoveryCheck::class,
            BrokenSharedReferencesCheck::class,
            UnregisteredBlockRowsCheck::class,
            PageTypePairsCheck::class,
            PageSubtypeBasesCheck::class,
            UnknownSchemaTypesCheck::class,
        ];
    }

    /** @param  list<HealthCheck>|null  $checks  Defaults to one instance of each of defaultChecks(). */
    public function __construct(private readonly ?array $checks = null) {}

    public function run(): HealthReport
    {
        $results = [];

        foreach ($this->resolveChecks() as $check) {
            $results[] = $this->runOne($check);
        }

        return new HealthReport($results);
    }

    /** @return list<HealthCheck> */
    private function resolveChecks(): array
    {
        if ($this->checks !== null) {
            return $this->checks;
        }

        return array_map(static fn (string $class): HealthCheck => app($class), self::defaultChecks());
    }

    private function runOne(HealthCheck $check): HealthCheckResult
    {
        try {
            return $check->run();
        } catch (Throwable $e) {
            return new HealthCheckResult(
                checkId: $check->id(),
                status: HealthStatus::Error,
                labelKey: "tbtop-cms::health.checks.{$check->id()}.label",
                messageKey: 'tbtop-cms::health.runner.threw',
                messageReplace: ['error' => $e->getMessage()],
                hintKey: 'tbtop-cms::health.runner.threw_hint',
            );
        }
    }
}
