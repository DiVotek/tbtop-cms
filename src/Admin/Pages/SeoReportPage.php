<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\AlertBlock;
use Tbtop\Admin\Dsl\ChartBuilder;
use Tbtop\Admin\Dsl\Color;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\Stat;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Frontend\Seo\SeoAudit;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Seo\Models\SeoAuditSnapshot;
use Tbtop\Cms\Seo\SeoAuditSnapshotWriter;

/**
 * Read-only, site-wide SEO report: one query for every published page (see
 * SeoAudit — one Page query, then plain PHP analysis, no per-page
 * SeoResolver runs), rendered as an Overview tab (KPI stats + charts, backed
 * by daily snapshots in tbtop_seo_audit_snapshots) and a Details tab
 * (per-page collapsibles).
 *
 * @phpstan-import-type Issue from SeoAudit
 */
class SeoReportPage extends AdminPage
{
    public static function path(): string
    {
        return 'seo-report';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'content',
            'label' => __('tbtop-cms::nav.items.seo_report'),
            'order' => 40,
            'icon' => 'search-check',
        ];
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.redirects');
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::seo_report.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::seo_report.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.content')],
            ['label' => __('tbtop-cms::nav.items.seo_report')],
        ];
    }

    public function view(S $s): Node
    {
        $issues = app(SeoAudit::class)->run();
        $pagesScanned = Page::query()->live()->whereNull('error_class')->count();

        app(SeoAuditSnapshotWriter::class)->write($issues, $pagesScanned);

        return $s->tabs([
            [
                'label' => __('tbtop-cms::seo_report.tabs.overview'),
                'body' => $this->overviewTab($s, $issues, $pagesScanned),
            ],
            [
                'label' => __('tbtop-cms::seo_report.tabs.details'),
                'body' => $this->detailsTab($s, $issues),
            ],
        ]);
    }

    /** @param  list<Issue>  $issues */
    private function overviewTab(S $s, array $issues, int $pagesScanned): Node
    {
        return $s->stack([
            $this->summaryAlert($s, $issues),
            $s->grid(['cols' => 4], $this->statCards($s, $issues, $pagesScanned)),
            $s->grid(['cols' => 2], [
                $this->severityDonut($s, $issues),
                $this->issueTypeBar($s, $issues),
            ]),
            $this->historyLine($s),
            $s->displayText(__('tbtop-cms::seo_report.charts.history_caption'))->variant('muted'),
        ]);
    }

    /**
     * @param  list<Issue>  $issues
     * @return list<Stat>
     */
    private function statCards(S $s, array $issues, int $pagesScanned): array
    {
        $pagesWithIssues = count(array_unique(array_column($issues, 'pageId')));
        $failCount = self::failCount($issues);
        $warnCount = count($issues) - $failCount;

        $sparklines = $this->sparklines();

        $pagesScannedStat = $s->stat(__('tbtop-cms::seo_report.stats.pages_scanned'))->value($pagesScanned);
        $pagesWithIssuesStat = $s->stat(__('tbtop-cms::seo_report.stats.pages_with_issues'))->value($pagesWithIssues);
        $failStat = $s->stat(__('tbtop-cms::seo_report.severities.fail'))->value($failCount);
        $warnStat = $s->stat(__('tbtop-cms::seo_report.severities.warn'))->value($warnCount);

        if ($sparklines !== null) {
            $pagesScannedStat->sparkline($sparklines['pages_scanned']);
            $pagesWithIssuesStat->sparkline($sparklines['pages_with_issues']);
            $failStat->sparkline($sparklines['fail_count']);
            $warnStat->sparkline($sparklines['warn_count']);
        }

        return [$pagesScannedStat, $pagesWithIssuesStat, $failStat, $warnStat];
    }

    /**
     * Up to the last ~30 days of snapshot history, oldest→newest, one array
     * per stat column — only built once at least 2 snapshots exist (a single
     * point is not a trend line worth rendering).
     *
     * @return array{pages_scanned: list<int>, pages_with_issues: list<int>, fail_count: list<int>, warn_count: list<int>}|null
     */
    private function sparklines(): ?array
    {
        if (SeoAuditSnapshot::query()->count() < 2) {
            return null;
        }

        // Latest 30, then re-sorted ascending: orderBy+limit alone would pin
        // the sparkline to the oldest month of history forever.
        $snapshots = SeoAuditSnapshot::query()->orderByDesc('captured_on')->limit(30)->get([
            'pages_scanned', 'pages_with_issues', 'fail_count', 'warn_count',
        ])->reverse()->values();

        return [
            'pages_scanned' => $snapshots->pluck('pages_scanned')->all(),
            'pages_with_issues' => $snapshots->pluck('pages_with_issues')->all(),
            'fail_count' => $snapshots->pluck('fail_count')->all(),
            'warn_count' => $snapshots->pluck('warn_count')->all(),
        ];
    }

    /** @param  list<Issue>  $issues */
    private function severityDonut(S $s, array $issues): ChartBuilder
    {
        $failCount = self::failCount($issues);
        $warnCount = count($issues) - $failCount;

        return $s->chart('seo-report-by-severity', 'donut')
            ->set('title', __('tbtop-cms::seo_report.charts.by_severity'))
            ->set('nameKey', 'label')
            ->set('series', [['dataKey' => 'value']])
            ->query(static fn (): array => [
                ['label' => __('tbtop-cms::seo_report.severities.fail'), 'value' => $failCount],
                ['label' => __('tbtop-cms::seo_report.severities.warn'), 'value' => $warnCount],
            ]);
    }

    /** @param  list<Issue>  $issues */
    private function issueTypeBar(S $s, array $issues): ChartBuilder
    {
        $counts = [];
        foreach ($issues as $issue) {
            $counts[$issue['issue']] = ($counts[$issue['issue']] ?? 0) + 1;
        }
        arsort($counts);

        return $s->chart('seo-report-by-issue-type', 'bar')
            ->set('title', __('tbtop-cms::seo_report.charts.by_issue_type'))
            ->set('xKey', 'label')
            ->set('series', [['dataKey' => 'value', 'label' => __('tbtop-cms::seo_report.charts.issue_count')]])
            ->query(static fn (): array => array_map(
                static fn (string $label, int $value): array => ['label' => $label, 'value' => $value],
                array_keys($counts),
                array_values($counts),
            ));
    }

    private function historyLine(S $s): ChartBuilder
    {
        return $s->chart('seo-report-history', 'line')
            ->set('title', __('tbtop-cms::seo_report.charts.history'))
            ->set('xKey', 'date')
            ->set('series', [
                ['dataKey' => 'fail_count', 'label' => __('tbtop-cms::seo_report.severities.fail')],
                ['dataKey' => 'warn_count', 'label' => __('tbtop-cms::seo_report.severities.warn')],
                ['dataKey' => 'pages_with_issues', 'label' => __('tbtop-cms::seo_report.stats.pages_with_issues')],
            ])
            ->query(static fn (): array => SeoAuditSnapshot::query()
                ->where('captured_on', '>=', now()->subDays(89))
                ->orderBy('captured_on')
                ->get()
                ->map(static fn (SeoAuditSnapshot $snapshot): array => [
                    'date' => $snapshot->captured_on->toDateString(),
                    'fail_count' => $snapshot->fail_count,
                    'warn_count' => $snapshot->warn_count,
                    'pages_with_issues' => $snapshot->pages_with_issues,
                ])
                ->all());
    }

    /** @param  list<Issue>  $issues */
    private function detailsTab(S $s, array $issues): Node
    {
        if ($issues === []) {
            return $s->stack([
                $s->displayAlert(__('tbtop-cms::seo_report.empty.description'))
                    ->title(__('tbtop-cms::seo_report.empty.title'))
                    ->color(Color::Success),
            ]);
        }

        $groups = [];
        foreach ($issues as $issue) {
            $groups[$issue['pageId']][] = $issue;
        }

        uasort($groups, static function (array $a, array $b): int {
            $aFail = self::failCount($a);
            $bFail = self::failCount($b);

            if (($aFail > 0) !== ($bFail > 0)) {
                return $aFail > 0 ? -1 : 1;
            }

            return count($b) <=> count($a);
        });

        $collapsibles = [];
        foreach ($groups as $pageId => $pageIssues) {
            $collapsibles[] = $this->pageCollapsible($s, (int) $pageId, $pageIssues);
        }

        return $s->stack($collapsibles);
    }

    /** @param  list<Issue>  $pageIssues */
    private function pageCollapsible(S $s, int $pageId, array $pageIssues): Node
    {
        $label = $this->pageGroupLabel($pageIssues);

        return $s->collapsible(['label' => $label, 'collapsed' => true], [
            $s->list('page-issues-' . $pageId)->items(static fn (): array => array_map(
                static fn (array $issue): array => [
                    'title' => $issue['issue'],
                    'meta' => $issue['detail'] . ($issue['locale'] !== '' ? " ({$issue['locale']})" : ''),
                    'color' => $issue['severity'] === 'fail' ? 'danger' : 'warning',
                ],
                $pageIssues,
            )),
        ]);
    }

    /** @param  list<Issue>  $pageIssues */
    private function pageGroupLabel(array $pageIssues): string
    {
        $pageLabel = $pageIssues[0]['page'];
        $failCount = self::failCount($pageIssues);
        $warnCount = count($pageIssues) - $failCount;

        $parts = [$pageLabel];
        if ($failCount > 0) {
            $parts[] = trans_choice('tbtop-cms::seo_report.page_group.fail_count', $failCount, ['count' => (string) $failCount]);
        }
        if ($warnCount > 0) {
            $parts[] = trans_choice('tbtop-cms::seo_report.page_group.warn_count', $warnCount, ['count' => (string) $warnCount]);
        }

        return implode(' · ', $parts);
    }

    /** @param  list<Issue>  $pageIssues */
    private static function failCount(array $pageIssues): int
    {
        return count(array_filter($pageIssues, static fn (array $issue): bool => $issue['severity'] === 'fail'));
    }

    /**
     * @param  list<Issue>  $issues
     */
    private function summaryAlert(S $s, array $issues): AlertBlock
    {
        if ($issues === []) {
            return $s->displayAlert(__('tbtop-cms::seo_report.summary.clean'))->color(Color::Success);
        }

        // Distinct page IDs, not labels: two pages sharing a title would
        // collapse into one, and one page with different localized labels
        // would count once per locale.
        $pageCount = count(array_unique(array_column($issues, 'pageId')));
        $failCount = self::failCount($issues);

        return $s->displayAlert(__('tbtop-cms::seo_report.summary.problems', [
            'count' => count($issues),
            'pages' => $pageCount,
        ]))->color($failCount > 0 ? Color::Danger : Color::Warning);
    }
}
