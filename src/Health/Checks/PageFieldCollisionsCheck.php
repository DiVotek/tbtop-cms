<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;
use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * `page.fields` is a page's type fields (base, then subtype) followed by its
 * backReferences(); a back-reference named like a field replaces that
 * field's value on the site. That precedence is intended, but rarely what a
 * host means, so it is reported. Only declared fields() names are compared:
 * keys a resolveFields() override computes are not known without a page.
 */
final class PageFieldCollisionsCheck implements HealthCheck
{
    public function __construct(private readonly PageTypeRegistry $registry) {}

    public function id(): string
    {
        return 'page_field_collisions';
    }

    public function run(): HealthCheckResult
    {
        $collisions = [];

        foreach ($this->registry->all() as $key => $type) {
            $base = $type->base();
            $hasBase = $base !== $key && $this->registry->has($base);
            $names = $this->collisions($hasBase ? [$this->registry->get($base), $type] : [$type]);

            // A clash the base has on its own is listed under the base, not again under each subtype.
            if ($hasBase) {
                $names = array_values(array_diff($names, $this->collisions([$this->registry->get($base)])));
            }

            if ($names !== []) {
                $collisions[] = "{$key}: " . implode(', ', $names);
            }
        }

        if ($collisions === []) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Ok,
                labelKey: 'tbtop-cms::health.checks.page_field_collisions.label',
                messageKey: 'tbtop-cms::health.checks.page_field_collisions.ok',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Warning,
            labelKey: 'tbtop-cms::health.checks.page_field_collisions.label',
            messageKey: 'tbtop-cms::health.checks.page_field_collisions.warning',
            messageReplace: ['types' => implode('; ', $collisions)],
            hintKey: 'tbtop-cms::health.checks.page_field_collisions.hints.warning',
        );
    }

    /**
     * @param  list<PageTypeInterface>  $types  base first, then subtype
     * @return list<string>
     */
    private function collisions(array $types): array
    {
        $fields = [];
        $backReferences = [];

        foreach ($types as $type) {
            foreach (S::normalizeChildren($type->fields(new S)) as $field) {
                if ($field instanceof Field) {
                    $fields[] = $field->name;
                }
            }
            $backReferences = [...$backReferences, ...array_keys($type->backReferences())];
        }

        return array_values(array_unique(array_intersect($backReferences, $fields)));
    }
}
