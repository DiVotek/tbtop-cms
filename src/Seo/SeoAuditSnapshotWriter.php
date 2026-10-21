<?php

declare(strict_types=1);

namespace Tbtop\Cms\Seo;

use Tbtop\Cms\Seo\Models\SeoAuditSnapshot;

/**
 * Admin-facing SEO subsystem (`Tbtop\Cms\Seo\*`), kept separate from
 * `Tbtop\Cms\Frontend\Seo\*` — that namespace is frontend-render concerns
 * (SeoResolver, JsonLd, SeoTemplates); this one is the admin report's own
 * history/persistence layer.
 *
 * @phpstan-import-type Issue from \Tbtop\Cms\Frontend\Seo\SeoAudit
 */
final class SeoAuditSnapshotWriter
{
    /**
     * Upserts today's row. No concurrency/atomic-increment handling — this
     * fires a few times a day (admin page loads, one cron call) and each
     * write simply overwrites the previous same-day counters.
     *
     * @param  list<Issue>  $issues
     */
    public function write(array $issues, int $pagesScanned): SeoAuditSnapshot
    {
        $failCount = 0;
        $warnCount = 0;
        $issueCounts = [];
        $pageIds = [];

        foreach ($issues as $issue) {
            $pageIds[$issue['pageId']] = true;
            // The stable key, not the translated label: the cron (app locale)
            // and an admin in another locale must write the same keys.
            $issueCounts[$issue['key']] = ($issueCounts[$issue['key']] ?? 0) + 1;

            if ($issue['severity'] === 'fail') {
                $failCount++;
            } else {
                $warnCount++;
            }
        }

        // A plain string here (e.g. today()->toDateString()) would round-trip
        // through the model's `date` cast as a full 'Y-m-d H:i:s' value on
        // INSERT but stay a bare 'Y-m-d' in this WHERE clause on the next
        // call — the mismatch fails to find today's row and races the unique
        // index on a second write. Passing the same Carbon instance the cast
        // itself produces keeps both sides formatted identically.
        return SeoAuditSnapshot::query()->updateOrCreate(
            ['captured_on' => today()],
            [
                'pages_scanned' => $pagesScanned,
                'pages_with_issues' => count($pageIds),
                'fail_count' => $failCount,
                'warn_count' => $warnCount,
                'issue_counts' => $issueCounts,
            ],
        );
    }
}
