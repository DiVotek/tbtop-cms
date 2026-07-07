<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin;

use Tbtop\Admin\Navigation\NavItem;
use Tbtop\Admin\Pages\Page;
use Tbtop\Admin\Panels\Panel;
use Tbtop\Admin\Panels\PanelConfig;
use Tbtop\Cms\Admin\Http\Middleware\EnsureCmsAdmin;
use Tbtop\Cms\Admin\Http\Middleware\ShareAdminAuth;
use Tbtop\Cms\Admin\Http\Middleware\SyncContentLocales;

class CmsPanel extends Panel
{
    public function configure(PanelConfig $panel): PanelConfig
    {
        $prefix = (string) config('tbtop-cms.admin.prefix', 'admin');

        return $panel
            ->id('cms')
            ->prefix($prefix)
            ->guard((string) config('tbtop-cms.admin.guard', 'web'))
            ->middleware(['web', EnsureCmsAdmin::class, ShareAdminAuth::class, SyncContentLocales::class])
            ->chrome(CmsChrome::class)
            ->navigation('topbar-sidebar')
            ->userMenuItems($this->userMenuItems($prefix))
            ->pages($this->pages());
    }

    /**
     * Profile-menu links in the top bar. Profile lives here, not in the
     * sidebar nav.
     *
     * @return list<NavItem>
     */
    private function userMenuItems(string $prefix): array
    {
        return [
            NavItem::make('Profile')->url('/' . trim($prefix, '/') . '/profile')->icon('user'),
        ];
    }

    /** @return list<class-string<Page>> */
    private function pages(): array
    {
        /** @var array<string, class-string<Page>|null> $pages */
        $pages = (array) config('tbtop-cms.admin.pages', []);

        return array_values(array_filter($pages));
    }
}
