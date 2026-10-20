<?php

declare(strict_types=1);

namespace Tbtop\Cms\Seo\Commands;

use Illuminate\Console\Command;
use Tbtop\Cms\Frontend\Seo\SeoAudit;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Seo\SeoAuditSnapshotWriter;

/**
 * Not scheduled by the package — a host wanting daily history from cron
 * schedules this itself (see docs/host/ai/seo.md). Without cron, a snapshot
 * is still written/updated on every SeoReportPage load, just coarser.
 */
class SnapshotSeoAuditCommand extends Command
{
    protected $signature = 'cms:seo:snapshot';

    protected $description = "Capture today's SEO audit snapshot (pages scanned, issue counts).";

    public function handle(SeoAuditSnapshotWriter $writer): int
    {
        $issues = app(SeoAudit::class)->run();
        $pagesScanned = Page::query()->live()->whereNull('error_class')->count();

        $snapshot = $writer->write($issues, $pagesScanned);

        $this->info(sprintf(
            'SEO snapshot for %s: %d pages scanned, %d fail(s), %d warning(s).',
            $snapshot->captured_on->toDateString(),
            $pagesScanned,
            $snapshot->fail_count,
            $snapshot->warn_count,
        ));

        return self::SUCCESS;
    }
}
