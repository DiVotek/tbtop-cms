<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\SharedBlock\SharedBlocks;

/**
 * Rows whose `shared` pointer leads nowhere: the shared block is gone or
 * now has another kind. The renderer draws a placeholder (or hides the
 * block) for each, so a visitor sees a gap nobody was told about. Trashed
 * pages count — a restore brings the gap back. One column per table is
 * read; the shared blocks load once.
 */
final class BrokenSharedReferencesCheck implements HealthCheck
{
    public function id(): string
    {
        return 'broken_shared_references';
    }

    public function run(): HealthCheckResult
    {
        $shared = new SharedBlocks;
        $broken = [];

        foreach (Page::withTrashed()->orderBy('id')->pluck('content', 'id') as $id => $content) {
            $count = self::brokenIn($shared, $content);
            if ($count > 0) {
                $broken[] = "page #{$id}: {$count}";
            }
        }
        foreach (Layout::query()->orderBy('slot')->pluck('content', 'slot') as $slot => $content) {
            $count = self::brokenIn($shared, $content);
            if ($count > 0) {
                $broken[] = "layout {$slot}: {$count}";
            }
        }

        if ($broken === []) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Ok,
                labelKey: 'tbtop-cms::health.checks.broken_shared_references.label',
                messageKey: 'tbtop-cms::health.checks.broken_shared_references.ok',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Warning,
            labelKey: 'tbtop-cms::health.checks.broken_shared_references.label',
            messageKey: 'tbtop-cms::health.checks.broken_shared_references.warning',
            messageReplace: ['containers' => implode('; ', $broken)],
            hintKey: 'tbtop-cms::health.checks.broken_shared_references.hints.warning',
        );
    }

    private static function brokenIn(SharedBlocks $shared, mixed $content): int
    {
        $count = 0;
        foreach (is_array($content) ? $content : [] as $row) {
            if (SharedBlocks::refOf($row) !== null && is_array($row) && $shared->dataOf($row, (string) ($row['kind'] ?? '')) === null) {
                $count++;
            }
        }

        return $count;
    }
}
