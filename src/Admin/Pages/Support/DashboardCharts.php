<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tbtop\Admin\Dsl\ChartBuilder;
use Tbtop\Admin\Dsl\Fields\Select;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Form\LeadStatus;
use Tbtop\Cms\Form\Models\FormSubmission;

/** Row 2 — forms analytics: lead-status donut and a daily submissions area chart. */
class DashboardCharts
{
    private const PERIODS = [7, 30, 90];

    /** @return list<Node|ChartBuilder> */
    public static function charts(S $s): array
    {
        return [self::leadsByStatus($s), self::submissionsDaily($s)];
    }

    private static function leadsByStatus(S $s): ChartBuilder
    {
        return $s->chart('leads-by-status', 'donut')
            ->set('title', __('tbtop-cms::dashboard.charts.leads_by_status'))
            ->set('nameKey', 'label')
            ->set('series', [['dataKey' => 'value']])
            ->query(self::leadsByStatusData(...));
    }

    /** @return list<array{label: string, value: int}> */
    private static function leadsByStatusData(): array
    {
        $counts = FormSubmission::query()->selectRaw('lead_status, count(*) as aggregate')
            ->groupBy('lead_status')->pluck('aggregate', 'lead_status');

        return array_map(static fn (LeadStatus $status): array => [
            'label' => __('tbtop-cms::dashboard.lead_status.' . $status->value),
            'value' => (int) ($counts[$status->value] ?? 0),
        ], LeadStatus::cases());
    }

    private static function submissionsDaily(S $s): ChartBuilder
    {
        return $s->chart('submissions-daily', 'area')
            ->set('title', __('tbtop-cms::dashboard.charts.submissions_daily'))
            ->set('xKey', 'date')
            ->set('series', [['dataKey' => 'value', 'label' => __('tbtop-cms::dashboard.charts.series_submissions')]])
            ->params([self::periodField($s)])
            ->query(self::submissionsDailyData(...));
    }

    private static function periodField(S $s): Select
    {
        return $s->select('period')->label(__('tbtop-cms::dashboard.charts.period'))
            ->default((string) self::PERIODS[1])
            ->options([
                ['value' => (string) self::PERIODS[0], 'label' => __('tbtop-cms::dashboard.charts.period_7')],
                ['value' => (string) self::PERIODS[1], 'label' => __('tbtop-cms::dashboard.charts.period_30')],
                ['value' => (string) self::PERIODS[2], 'label' => __('tbtop-cms::dashboard.charts.period_90')],
            ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array{date: string, value: int}>
     */
    private static function submissionsDailyData(Request $request, array $params): array
    {
        $days = (int) ($params['period'] ?? self::PERIODS[1]);
        $days = in_array($days, self::PERIODS, true) ? $days : self::PERIODS[1];
        $since = Carbon::today()->subDays($days - 1);

        $counts = FormSubmission::query()->where('created_at', '>=', $since)
            ->get(['created_at'])
            ->countBy(static fn (FormSubmission $s): string => $s->created_at->toDateString());

        return collect(range(0, $days - 1))
            ->map(static fn (int $offset): string => $since->copy()->addDays($offset)->toDateString())
            ->map(static fn (string $date): array => ['date' => $date, 'value' => (int) ($counts[$date] ?? 0)])
            ->all();
    }
}
