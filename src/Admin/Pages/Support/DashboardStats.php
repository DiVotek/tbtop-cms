<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Tbtop\Admin\Dsl\Color;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\Stat;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Form\LeadStatus;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

/** Row 1 — top-line KPI stat cards, each trended over the last 14 days. */
class DashboardStats
{
    private const TREND_DAYS = 14;

    /** @return list<Stat> */
    public static function cards(S $s): array
    {
        return [
            self::pagesCard($s),
            self::submissionsCard($s),
            self::mediaCard($s),
            self::layoutsCard($s),
        ];
    }

    private static function pagesCard(S $s): Stat
    {
        $byStatus = Page::query()->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')->pluck('aggregate', 'status');
        $published = (int) ($byStatus[PageStatus::Published->value] ?? 0);
        $draft = (int) ($byStatus[PageStatus::Draft->value] ?? 0);

        return $s->stat(__('tbtop-cms::dashboard.stats.pages'))
            ->value($published + $draft)
            ->description(__('tbtop-cms::dashboard.stats.pages_description', [
                'published' => $published,
                'draft' => $draft,
            ]))
            ->icon('file-text')
            ->sparkline(self::dailyCounts(Page::query()), 'bottom');
    }

    private static function submissionsCard(S $s): Stat
    {
        $needsReview = FormSubmission::query()->where('lead_status', LeadStatus::New)->count();

        $stat = $s->stat(__('tbtop-cms::dashboard.stats.submissions_new'))
            ->value($needsReview)
            ->icon('inbox')
            ->sparkline(self::dailyCounts(FormSubmission::query()), 'bottom');

        return $needsReview > 0
            ? $stat->description(__('tbtop-cms::dashboard.stats.submissions_needs_review'))->color(Color::Warning)
            : $stat;
    }

    private static function mediaCard(S $s): Stat
    {
        $count = Media::query()->count();
        $size = (int) Media::query()->sum('size');

        return $s->stat(__('tbtop-cms::dashboard.stats.media'))
            ->value($count)
            ->description(__('tbtop-cms::dashboard.stats.media_description', [
                'size' => Number::fileSize($size),
            ]))
            ->icon('image')
            ->sparkline(self::dailyCounts(Media::query()), 'bottom');
    }

    private static function layoutsCard(S $s): Stat
    {
        $layouts = Layout::query()->count();

        return $s->stat(__('tbtop-cms::dashboard.stats.layouts'))
            ->value($layouts)
            ->description(__('tbtop-cms::dashboard.stats.layouts_description', [
                'layouts' => $layouts,
            ]))
            ->icon('layout-template')
            ->sparkline(self::dailyCounts(Layout::query()), 'bottom');
    }

    /**
     * Row counts created per day over the trailing TREND_DAYS window, oldest first.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return list<int>
     */
    private static function dailyCounts(Builder $query): array
    {
        $since = Carbon::today()->subDays(self::TREND_DAYS - 1);

        $counts = $query->where('created_at', '>=', $since)
            ->get(['created_at'])
            ->countBy(static function (Model $row): string {
                /** @var Carbon $createdAt */
                $createdAt = $row->getAttribute('created_at');

                return $createdAt->toDateString();
            });

        return collect(range(0, self::TREND_DAYS - 1))
            ->map(static fn (int $offset): string => $since->copy()->addDays($offset)->toDateString())
            ->map(static fn (string $date): int => (int) ($counts[$date] ?? 0))
            ->all();
    }
}
