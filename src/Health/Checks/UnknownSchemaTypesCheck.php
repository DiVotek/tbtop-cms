<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Frontend\Seo\Schema\SchemaTypeRegistry;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * Page types (subtypes included) whose schemaTypes() name a key no schema
 * type registers. SchemaSelector skips such a key, so the page publishes the
 * next known one or the fallback — silently, which is what this reports.
 */
final class UnknownSchemaTypesCheck implements HealthCheck
{
    public function __construct(
        private readonly PageTypeRegistry $pageTypes,
        private readonly SchemaTypeRegistry $schemaTypes,
    ) {}

    public function id(): string
    {
        return 'unknown_schema_types';
    }

    public function run(): HealthCheckResult
    {
        $unknown = [];

        foreach ($this->pageTypes->all() as $key => $type) {
            $keys = array_filter($type->schemaTypes(), fn (string $schema): bool => ! $this->schemaTypes->has($schema));

            if ($keys !== []) {
                $unknown[] = "{$key}: " . implode(', ', $keys);
            }
        }

        if ($unknown === []) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Ok,
                labelKey: 'tbtop-cms::health.checks.unknown_schema_types.label',
                messageKey: 'tbtop-cms::health.checks.unknown_schema_types.ok',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Warning,
            labelKey: 'tbtop-cms::health.checks.unknown_schema_types.label',
            messageKey: 'tbtop-cms::health.checks.unknown_schema_types.warning',
            messageReplace: ['types' => implode('; ', $unknown)],
            hintKey: 'tbtop-cms::health.checks.unknown_schema_types.hints.warning',
        );
    }
}
