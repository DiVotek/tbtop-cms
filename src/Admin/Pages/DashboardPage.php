<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page;
use Tbtop\Cms\Admin\Pages\Support\DashboardCharts;
use Tbtop\Cms\Admin\Pages\Support\DashboardRecent;
use Tbtop\Cms\Admin\Pages\Support\DashboardStats;
use Tbtop\Cms\Admin\Pages\Support\DashboardSystem;
use Tbtop\Cms\Admin\Support\CmsAbilities;

class DashboardPage extends Page
{
    public static function path(): string
    {
        return 'dashboard';
    }

    /**
     * Hidden from the sidebar — reached via the top-bar logo.
     *
     * @return array<string, mixed>|null
     */
    public static function nav(): ?array
    {
        return null;
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::dashboard.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::dashboard.welcome');
    }

    /** @return list<ActionBuilder|Node> */
    #[\Override]
    public function headerActions(S $s): array
    {
        $actions = [
            $s->action('dashboardRefresh')->label(__('tbtop-cms::dashboard.actions.refresh'))
                ->icon('refresh-cw')->visit(url()->current()),
            $s->action('dashboardViewSite')->label(__('tbtop-cms::dashboard.actions.view_site'))
                ->icon('external-link')->visit(url('/'), newTab: true),
        ];

        if (Route::has('tbtop.cms.pages-page')) {
            $actions[] = $s->action('dashboardNewPage')->label(__('tbtop-cms::dashboard.actions.new_page'))
                ->color('primary')->visit(route('tbtop.cms.pages-page'));
        }

        return $actions;
    }

    public function view(S $s): Node
    {
        $showSubmissions = ! CmsAbilities::enabled() || Gate::allows('tbtop-cms.submissions');

        return $s->stack([
            $s->grid(['cols' => 4], DashboardStats::cards($s, $showSubmissions)),
            ...($showSubmissions ? [$s->grid(['cols' => 2], DashboardCharts::charts($s))] : []),
            $s->grid(['cols' => 2], DashboardRecent::cards($s, $showSubmissions)),
            $this->systemSection($s),
        ]);
    }

    private function systemSection(S $s): Node
    {
        return $s->section(['title' => __('tbtop-cms::dashboard.system.title')], [
            $s->grid(['cols' => 3], DashboardSystem::metrics($s)),
            $s->grid(['cols' => 2], DashboardSystem::environmentCards($s)),
        ]);
    }
}
