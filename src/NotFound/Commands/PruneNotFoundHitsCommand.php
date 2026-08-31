<?php

declare(strict_types=1);

namespace Tbtop\Cms\NotFound\Commands;

use Illuminate\Console\Command;
use Tbtop\Cms\NotFound\Models\NotFoundHit;
use Tbtop\Cms\Settings\Settings;

/**
 * Scheduled daily by the package itself (see
 * CmsServiceProvider::wireNotFoundPruneSchedule()); opt out with
 * tbtop-cms.not_found.auto_prune => false and schedule it yourself, or not at
 * all — see docs/host/ai/seo.md, "404 log".
 */
class PruneNotFoundHitsCommand extends Command
{
    protected $signature = 'cms:not-found:prune';

    protected $description = 'Delete 404 log rows not seen within the configured retention window.';

    public function handle(): int
    {
        // A stored null/empty value reads as the default, matching the
        // missing-key case — Settings::get() applies its $default only when
        // the KEY is absent, and a settings form saved with the field left
        // blank stores null, which would cast to a 0-day retention and wipe
        // the whole log on every (now daily, scheduled) run. Same rule as
        // RecordsNotFoundHit::enabled().
        $days = (int) app(Settings::class)->get('seo.not_found.retention_days', 90);

        if ($days < 1) {
            $days = 90;
        }

        $cutoff = now()->subDays($days);

        $deleted = NotFoundHit::query()->where('last_seen_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} 404 log " . ($deleted === 1 ? 'row' : 'rows') . " older than {$days} days.");

        return self::SUCCESS;
    }
}
