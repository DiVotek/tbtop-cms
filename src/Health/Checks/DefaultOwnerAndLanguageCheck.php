<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;
use Tbtop\Cms\Language\Languages;

/**
 * Two independent invariants the rest of the admin quietly assumes hold:
 * an owner row that can never be revoked/edited away (see UsersPage's
 * owner-protection docblock — with zero owners nobody can ever fix access
 * again except by DB surgery), and an active default frontend language
 * (Languages::defaultLocale() feeds every frontend URL/locale resolution;
 * an empty default breaks the frontend for every visitor, not just admins).
 * Both are error-level: neither has a safe fallback the CMS can render for.
 */
final class DefaultOwnerAndLanguageCheck implements HealthCheck
{
    public function __construct(private readonly Languages $languages) {}

    public function id(): string
    {
        return 'owner_and_default_language';
    }

    public function run(): HealthCheckResult
    {
        $hasOwner = CmsUserProfile::query()->where('is_owner', true)->exists();
        $defaultLanguage = $this->languages->default();
        $hasDefaultLanguage = $defaultLanguage !== null && $defaultLanguage['is_frontend_active'];

        if (! $hasOwner && ! $hasDefaultLanguage) {
            return $this->error('tbtop-cms::health.checks.owner_and_default_language.hints.both_missing');
        }

        if (! $hasOwner) {
            return $this->error('tbtop-cms::health.checks.owner_and_default_language.hints.owner_missing');
        }

        if (! $hasDefaultLanguage) {
            return $this->error('tbtop-cms::health.checks.owner_and_default_language.hints.language_missing');
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Ok,
            labelKey: 'tbtop-cms::health.checks.owner_and_default_language.label',
            messageKey: 'tbtop-cms::health.checks.owner_and_default_language.ok',
        );
    }

    private function error(string $hintKey): HealthCheckResult
    {
        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Error,
            labelKey: 'tbtop-cms::health.checks.owner_and_default_language.label',
            messageKey: 'tbtop-cms::health.checks.owner_and_default_language.missing',
            hintKey: $hintKey,
        );
    }
}
