<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Illuminate\Support\Facades\Route;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * The admin login route is named "tbtop.{panelId}.{pageSlug}", and
 * Page::slug() derives the slug from the class basename
 * (Str::kebab('LoginPage') === 'login-page') rather than from Page::path()
 * — so the registered name is "tbtop.cms.login-page", not "tbtop.cms.login".
 * That route is always registered once tbtop/admin's own service provider
 * boots — it does not depend on host wiring. The frontend catch-all
 * ("tbtop-cms.page") is different: the host must call Cms::frontendRoutes()
 * itself from its own routes/web.php (see README "Host application
 * requirements"), so a missing frontend route is a real, common integration
 * gap this check exists to catch.
 */
final class RoutesRegisteredCheck implements HealthCheck
{
    private const string ADMIN_LOGIN_ROUTE = 'tbtop.cms.login-page';

    private const string FRONTEND_PAGE_ROUTE = 'tbtop-cms.page';

    public function id(): string
    {
        return 'routes_registered';
    }

    public function run(): HealthCheckResult
    {
        $adminMissing = ! Route::has(self::ADMIN_LOGIN_ROUTE);
        $frontendMissing = ! Route::has(self::FRONTEND_PAGE_ROUTE);

        if ($adminMissing && $frontendMissing) {
            return $this->error('tbtop-cms::health.checks.routes_registered.hints.both_missing');
        }

        if ($adminMissing) {
            return $this->error('tbtop-cms::health.checks.routes_registered.hints.admin_missing');
        }

        if ($frontendMissing) {
            return $this->error('tbtop-cms::health.checks.routes_registered.hints.frontend_missing');
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Ok,
            labelKey: 'tbtop-cms::health.checks.routes_registered.label',
            messageKey: 'tbtop-cms::health.checks.routes_registered.ok',
        );
    }

    private function error(string $hintKey): HealthCheckResult
    {
        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Error,
            labelKey: 'tbtop-cms::health.checks.routes_registered.label',
            messageKey: 'tbtop-cms::health.checks.routes_registered.missing',
            hintKey: $hintKey,
        );
    }
}
