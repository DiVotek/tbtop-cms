<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\AlertBlock;
use Tbtop\Admin\Dsl\Color;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\SeoReportQuery;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Frontend\Seo\SeoAudit;

/**
 * Read-only, site-wide SEO report: one row per (page, locale, problem),
 * re-run on every page load (see SeoAudit — one query for every published
 * page, then plain PHP analysis, no per-page SeoResolver runs).
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
        if (! SeoReportQuery::supported()) {
            return $this->fallbackView($s);
        }

        $issues = app(SeoAudit::class)->run();

        $table = $s->table('seoReport')
            ->query(static fn () => SeoReportQuery::build($issues))
            ->columns([
                Column::make('severity')->label(__('tbtop-cms::seo_report.columns.severity'))
                    ->formatUsing($this->severityLabel(...))
                    ->badge([
                        __('tbtop-cms::seo_report.severities.fail') => Color::Danger,
                        __('tbtop-cms::seo_report.severities.warn') => Color::Warning,
                    ]),
                Column::make('issue')->label(__('tbtop-cms::seo_report.columns.issue'))->emphasized(),
                Column::make('page')->label(__('tbtop-cms::seo_report.columns.page'))->searchable(),
                Column::make('locale')->label(__('tbtop-cms::seo_report.columns.locale')),
                Column::make('detail')->label(__('tbtop-cms::seo_report.columns.detail'))->muted()->wrap(),
            ])
            ->searchable(['page'])
            ->defaultSort('sort_key', 'asc')
            ->toolbar(false)
            ->emptyState(
                __('tbtop-cms::seo_report.empty.title'),
                __('tbtop-cms::seo_report.empty.description'),
                'search-check',
            )
            ->paginate(25, [25, 50, 100]);

        return $s->stack([$this->summaryAlert($s, $issues), $table]);
    }

    private function severityLabel(mixed $value): string
    {
        return $value === 'fail'
            ? __('tbtop-cms::seo_report.severities.fail')
            : __('tbtop-cms::seo_report.severities.warn');
    }

    /**
     * @param  list<array{severity: 'warn'|'fail', pageId: int}>  $issues
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
        $failCount = count(array_filter($issues, static fn (array $issue): bool => $issue['severity'] === 'fail'));

        return $s->displayAlert(__('tbtop-cms::seo_report.summary.problems', [
            'count' => count($issues),
            'pages' => $pageCount,
        ]))->color($failCount > 0 ? Color::Danger : Color::Warning);
    }

    /**
     * Rendering without pdo_sqlite: the DSL table needs the :memory:
     * connection (see SeoReportQuery), but SeoAudit itself doesn't — so the
     * report still runs and renders as a plain key/value list behind an
     * alert explaining the degraded view, same fallback SiteHealthPage uses.
     */
    private function fallbackView(S $s): Node
    {
        $issues = app(SeoAudit::class)->run();

        $rows = [];
        foreach ($issues as $index => $issue) {
            $key = $this->severityLabel($issue['severity']) . ' — ' . $issue['issue'] . ' — ' . $issue['page']
                . ($issue['locale'] !== '' ? " ({$issue['locale']})" : '') . " #{$index}";
            $rows[$key] = $issue['detail'];
        }

        return $s->stack([
            $s->displayAlert(__('tbtop-cms::seo_report.page.sqlite_missing')),
            $this->summaryAlert($s, $issues),
            $s->displayKeyValue($rows),
        ]);
    }
}
