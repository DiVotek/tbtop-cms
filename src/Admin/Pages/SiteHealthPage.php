<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\Color;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\HealthReportQuery;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Health\HealthCheckRunner;

/**
 * Read-only diagnostic page: one row per registered health check, re-run on
 * every page load (see HealthReportQuery — checks are cheap: file_exists,
 * a handful of config reads, one write+delete probe on the media disk).
 * No actions; `cms:doctor` is the same report for CI/deploy scripts.
 */
class SiteHealthPage extends AdminPage
{
    public static function path(): string
    {
        return 'site-health';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'settings',
            'label' => __('tbtop-cms::nav.items.site_health'),
            'order' => 50,
            'icon' => 'heart-pulse',
        ];
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.settings');
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::health.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::health.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.settings')],
            ['label' => __('tbtop-cms::nav.items.site_health')],
        ];
    }

    public function view(S $s): Node
    {
        if (! HealthReportQuery::supported()) {
            return $this->fallbackView($s);
        }

        $table = $s->table('siteHealth')
            ->query(static fn () => HealthReportQuery::build())
            ->columns([
                Column::make('status')->label(__('tbtop-cms::health.columns.status'))
                    ->formatUsing($this->statusLabel(...))
                    ->badge([
                        __('tbtop-cms::health.statuses.ok') => Color::Success,
                        __('tbtop-cms::health.statuses.warning') => Color::Warning,
                        __('tbtop-cms::health.statuses.error') => Color::Danger,
                    ]),
                Column::make('label')->label(__('tbtop-cms::health.columns.check'))->emphasized(),
                Column::make('message')->label(__('tbtop-cms::health.columns.message'))->wrap(),
                Column::make('hint')->label(__('tbtop-cms::health.columns.hint'))->muted()->wrap(),
            ])
            ->defaultSort('check_id', 'asc')
            ->toolbar(false)
            ->paginate(25, [25]);

        return $s->stack([$table]);
    }

    private function statusLabel(mixed $value): string
    {
        return match ($value) {
            'warning' => __('tbtop-cms::health.statuses.warning'),
            'error' => __('tbtop-cms::health.statuses.error'),
            default => __('tbtop-cms::health.statuses.ok'),
        };
    }

    /**
     * Rendering without pdo_sqlite: the DSL table needs the :memory:
     * connection (see HealthReportQuery), but the checks themselves don't —
     * so the report still runs and renders as a plain key/value list behind
     * an alert explaining the degraded view, rather than the page fataling
     * on a driver the host never declared it needed.
     */
    private function fallbackView(S $s): Node
    {
        $report = app(HealthCheckRunner::class)->run();

        $rows = [];
        foreach ($report->results as $result) {
            $line = $this->statusLabel($result->status->value) . ' — ' . $result->message();
            $hint = $result->hint();
            $rows[$result->label()] = $hint === null ? $line : $line . ' ' . $hint;
        }

        return $s->stack([
            $s->displayAlert(__('tbtop-cms::health.page.sqlite_missing')),
            $s->displayKeyValue($rows),
        ]);
    }
}
