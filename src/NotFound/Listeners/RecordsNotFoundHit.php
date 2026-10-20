<?php

declare(strict_types=1);

namespace Tbtop\Cms\NotFound\Listeners;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tbtop\Cms\NotFound\Events\PageNotFound;
use Tbtop\Cms\NotFound\Models\NotFoundHit;
use Tbtop\Cms\NotFound\Support\NotFoundNoise;
use Tbtop\Cms\Settings\Settings;

/**
 * Deferred to app()->terminating(), same as NotifiesAdminsOfSubmission — this
 * package ships no queue, and a visitor hitting a dead URL must not wait on a
 * write. The aggregation itself is a real upsert (not updateOrCreate, which
 * is select-then-insert and races under concurrent bot traffic, and can't
 * express `hits + 1` atomically).
 *
 * The on/off switch lives in Settings (`seo.not_found.enabled`), not config —
 * a single operator-facing toggle on the SEO settings page, rather than two
 * competing kill switches. The ignore list stays config-only
 * (`tbtop-cms.not_found.ignore`): it's a deployment-level noise filter (scanner
 * patterns), not something an operator tunes per site from the UI.
 */
final class RecordsNotFoundHit
{
    public function __construct(private readonly Application $app) {}

    public function handle(PageNotFound $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        /** @var list<string> $ignore */
        $ignore = config('tbtop-cms.not_found.ignore', NotFoundNoise::DEFAULT_IGNORE);

        if (NotFoundNoise::shouldIgnore($event->path, $ignore)) {
            return;
        }

        $this->app->terminating(function () use ($event): void {
            $this->write($event);
        });
    }

    /**
     * A stored JSON null reads as "on", matching the missing-key default —
     * Settings::get() applies its $default only when the KEY is absent, and
     * the settings form writes whatever the boolean field submitted, so an
     * untouched toggle must not silently stop recording. Same rule as
     * SeoResolver::indexingEnabled().
     */
    private function enabled(): bool
    {
        $value = app(Settings::class)->get('seo.not_found.enabled', true);

        return $value === null || (bool) $value;
    }

    private function write(PageNotFound $event): void
    {
        $now = now();
        $referer = $this->truncate($event->referer, 2048);
        $userAgent = $this->truncate($event->userAgent, 512);
        $table = (new NotFoundHit)->getTable();
        $query = DB::table($table);

        $query->upsert(
            [[
                'locale' => $event->locale,
                'path' => $event->path,
                'path_hash' => hash('sha256', $event->path),
                'hits' => 1,
                'last_referer' => $referer,
                'last_user_agent' => $userAgent,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'is_ignored' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['locale', 'path_hash'],
            [
                // Qualified: inside ON CONFLICT DO UPDATE, postgres sees both the
                // target row and the excluded pseudo-table, and rejects a bare
                // `hits` as ambiguous. The grammar wraps it with the
                // connection's table prefix; $table is the model's own name.
                'hits' => DB::raw($query->getGrammar()->wrap("{$table}.hits") . ' + 1'),
                'last_seen_at' => $now,
                'last_referer' => $referer,
                'last_user_agent' => $userAgent,
                'updated_at' => $now,
            ],
        );
    }

    private function truncate(?string $value, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Str::limit($value, $limit, '');
    }
}
