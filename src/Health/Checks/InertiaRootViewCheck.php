<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Health\Checks\Concerns\ReadsFiles;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * Only the frontend root view matters here. The head partial renders SEO tags
 * for crawlers, which never reach the admin panel — and since `admin:install`
 * publishes a dedicated root view for the panel, the two are separate files
 * on a current host anyway. So this inspects one file: whichever
 * `tbtop-cms.render.root_view` names, or "app".
 *
 * The include has to sit above the Inertia head tag (`<x-inertia::head>` on
 * Inertia 3, `@inertiaHead` on Inertia 2) so the package's tags win the
 * reconciliation. A positional source check covers the documented directives
 * without requiring a full Blade parser.
 */
final class InertiaRootViewCheck implements HealthCheck
{
    use ReadsFiles;

    private const string DEFAULT_ROOT_VIEW = 'app';

    private const string REQUIRED_INCLUDE = "@include('tbtop-cms::head')";

    /** @var list<string> */
    private const array INERTIA_HEAD_DIRECTIVES = ['@inertiaHead', '<x-inertia::head>'];

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

        $contents = $this->readFile($path);

        // Error is the right severity — an unreadable root view means every
        // page render fatals — but the message must name the actual problem,
        // not surface as the runner's generic "check crashed".
        if ($contents === null) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Error,
                labelKey: 'tbtop-cms::health.checks.inertia_root_view.label',
                messageKey: 'tbtop-cms::health.checks.inertia_root_view.unreadable',
                messageReplace: ['path' => $path],
                hintKey: 'tbtop-cms::health.checks.inertia_root_view.hints.unreadable',
                hintReplace: ['path' => $path],
            );
        }

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

        $includePosition = strpos($contents, self::REQUIRED_INCLUDE);

        foreach (self::INERTIA_HEAD_DIRECTIVES as $directive) {
            $directivePosition = strpos($contents, $directive);

            if ($directivePosition !== false && $includePosition > $directivePosition) {
                return new HealthCheckResult(
                    checkId: $this->id(),
                    status: HealthStatus::Error,
                    labelKey: 'tbtop-cms::health.checks.inertia_root_view.label',
                    messageKey: 'tbtop-cms::health.checks.inertia_root_view.include_after_head',
                    messageReplace: ['path' => $path],
                    hintKey: 'tbtop-cms::health.checks.inertia_root_view.hints.missing_include',
                );
            }
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
