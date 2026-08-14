<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Illuminate\Support\Facades\File;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * Two independent root views feed the same missing-include failure mode:
 * - the frontend uses `tbtop-cms.render.root_view` when set, else falls
 *   back to whatever the admin panel's root view resolves to.
 * - the admin panel's own root view defaults to "app"
 *   (Tbtop\Admin\Panels\PanelConfig::$rootView) and CmsPanel never
 *   overrides it, so it is effectively fixed at "app" for this package.
 * Both ultimately resolve to the same file when the host has not published
 * a dedicated frontend root view, so this check only needs to inspect one
 * physical file — whichever `tbtop-cms.render.root_view` names, or "app" — for
 * the `@include('tbtop-cms::head')` line the README requires above `@inertiaHead`.
 */
final class InertiaRootViewCheck implements HealthCheck
{
    private const string DEFAULT_ROOT_VIEW = 'app';

    private const string REQUIRED_INCLUDE = "@include('tbtop-cms::head')";

    public function id(): string
    {
        return 'inertia_root_view';
    }

    public function run(): HealthCheckResult
    {
        $viewName = $this->rootViewName();
        $path = $this->resolveViewPath($viewName);

        if ($path === null) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Error,
                labelKey: 'tbtop-cms::health.checks.inertia_root_view.label',
                messageKey: 'tbtop-cms::health.checks.inertia_root_view.missing_view',
                messageReplace: ['view' => $viewName],
                hintKey: 'tbtop-cms::health.checks.inertia_root_view.hints.missing_view',
                hintReplace: ['view' => $viewName],
            );
        }

        $contents = File::get($path);

        if (! str_contains($contents, self::REQUIRED_INCLUDE)) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Error,
                labelKey: 'tbtop-cms::health.checks.inertia_root_view.label',
                messageKey: 'tbtop-cms::health.checks.inertia_root_view.missing_include',
                messageReplace: ['path' => $path],
                hintKey: 'tbtop-cms::health.checks.inertia_root_view.hints.missing_include',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Ok,
            labelKey: 'tbtop-cms::health.checks.inertia_root_view.label',
            messageKey: 'tbtop-cms::health.checks.inertia_root_view.ok',
        );
    }

    private function rootViewName(): string
    {
        $configured = config('tbtop-cms.render.root_view');

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_ROOT_VIEW;
    }

    private function resolveViewPath(string $viewName): ?string
    {
        if (! view()->exists($viewName)) {
            return null;
        }

        $path = view($viewName)->getPath();

        return $path === '' ? null : $path;
    }
}
