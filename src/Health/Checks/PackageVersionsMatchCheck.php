<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\File;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;
use Throwable;

/**
 * PHP and JS halves of tbtop/admin are versioned and released together but
 * installed through two independent package managers (Composer, npm/bun) —
 * nothing stops a host from bumping one without the other. Neither side
 * being resolvable is itself only a warning (e.g. a fresh install before
 * `npm install`), not an error: this check exists to catch silent drift,
 * not to gate on the JS package existing at all.
 */
final class PackageVersionsMatchCheck implements HealthCheck
{
    public function id(): string
    {
        return 'package_versions_match';
    }

    public function run(): HealthCheckResult
    {
        $phpVersion = $this->phpVersion();
        $jsVersion = $this->jsVersion();

        if ($phpVersion === null || $jsVersion === null) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Warning,
                labelKey: 'tbtop-cms::health.checks.package_versions_match.label',
                messageKey: 'tbtop-cms::health.checks.package_versions_match.unknown',
                messageReplace: [
                    'php' => $phpVersion ?? __('tbtop-cms::health.checks.package_versions_match.unknown_value'),
                    'js' => $jsVersion ?? __('tbtop-cms::health.checks.package_versions_match.unknown_value'),
                ],
                hintKey: 'tbtop-cms::health.checks.package_versions_match.hints.unknown',
            );
        }

        if (! $this->versionsMatch($phpVersion, $jsVersion)) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Warning,
                labelKey: 'tbtop-cms::health.checks.package_versions_match.label',
                messageKey: 'tbtop-cms::health.checks.package_versions_match.mismatch',
                messageReplace: ['php' => $phpVersion, 'js' => $jsVersion],
                hintKey: 'tbtop-cms::health.checks.package_versions_match.hints.mismatch',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Ok,
            labelKey: 'tbtop-cms::health.checks.package_versions_match.label',
            messageKey: 'tbtop-cms::health.checks.package_versions_match.ok',
            messageReplace: ['version' => $phpVersion],
        );
    }

    private function phpVersion(): ?string
    {
        try {
            $version = InstalledVersions::getPrettyVersion('tbtop/admin');
        } catch (Throwable) {
            return null;
        }

        return $version === null ? null : $version;
    }

    private function jsVersion(): ?string
    {
        $path = base_path('node_modules/@tbtop/inertia-admin/package.json');

        if (! File::exists($path)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $version = $decoded['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Compares bare dot-separated version numbers (major.minor.patch),
     * stripping a leading "v" and any composer pretty-version metadata
     * (dev-branch suffixes, build/pre-release tags) neither side needs to
     * agree on byte-for-byte — only the release train they track.
     */
    private function versionsMatch(string $php, string $js): bool
    {
        return $this->normalize($php) === $this->normalize($js);
    }

    private function normalize(string $version): string
    {
        $version = ltrim($version, 'vV');
        $version = preg_replace('/[+-].*$/', '', $version) ?? $version;

        return $version;
    }
}
