<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin;

use Inertia\Middleware as InertiaMiddleware;
use Tbtop\Admin\Navigation\NavGroup;
use Tbtop\Admin\Navigation\NavItem;
use Tbtop\Admin\Pages\Page;
use Tbtop\Admin\Panels\Panel;
use Tbtop\Admin\Panels\PanelConfig;
use Tbtop\Cms\Admin\Http\Middleware\AuthorizeMediaApi;
use Tbtop\Cms\Admin\Http\Middleware\EnsureCmsAdmin;
use Tbtop\Cms\Admin\Http\Middleware\ShareAdminAuth;
use Tbtop\Cms\Admin\Http\Middleware\SyncContentLocales;
use Tbtop\Cms\Admin\Pages\CollectionEntriesPage;
use Tbtop\Cms\Collection\CollectionRegistry;

class CmsPanel extends Panel
{
    public function configure(PanelConfig $panel): PanelConfig
    {
        $prefix = (string) config('tbtop-cms.admin.prefix', 'admin');

        return $panel
            ->id('cms')
            ->prefix($prefix)
            ->guard((string) config('tbtop-cms.admin.guard', 'web'))
            // The panel has its own Vite entry (resources/js/admin.tsx, published
            // by admin:install), so it needs its own Blade root view. PanelConfig
            // defaults to 'app' — the host's public root view, which loads the
            // frontend bundle and cannot resolve the admin/page component.
            ->rootView('admin')
            // Inertia\Middleware is what turns session-flashed validation errors
            // into the `errors` prop after a redirect-back. tbtop/admin leaves
            // it to the host's `web` group; a host without HandleInertiaRequests
            // there would get a silent form resync on every rejected save.
            ->middleware(['web', InertiaMiddleware::class, EnsureCmsAdmin::class, AuthorizeMediaApi::class, ShareAdminAuth::class, SyncContentLocales::class])
            ->chrome(CmsChrome::class)
            // Product brand (the CMS itself, not the host site) shown in the topbar
            // and on the login card. A logo will replace/accompany it later.
            ->brand('TableTop CMS')
            ->locales(['en', 'uk'])
            ->density('compact')
            ->maxContentWidth('full')
            ->navigation('topbar-sidebar')
            ->userMenuItems($this->userMenuItems($prefix))
            ->navigationGroups($this->navigationGroups())
            ->navigationItems($this->collectionItems($prefix))
            ->pages($this->pages());
    }

    /**
     * Sidebar group headers: icon + collapse toggle. The first group opens by
     * default, the rest start collapsed.
     *
     * @return list<NavGroup>
     */
    private function navigationGroups(): array
    {
        return [
            NavGroup::make('content')->label(fn () => __('tbtop-cms::nav.groups.content'))->icon('file-text')->collapsible(),
            NavGroup::make('design')->label(fn () => __('tbtop-cms::nav.groups.design'))->icon('star')->collapsed(),
            NavGroup::make('settings')->label(fn () => __('tbtop-cms::nav.groups.settings'))->icon('lock')->collapsed(),
        ];
    }

    /** @return list<NavItem> */
    private function userMenuItems(string $prefix): array
    {
        // Closure label: panel config is built once at boot, an eager
        // __() here would freeze on the boot locale.
        return [
            NavItem::make(static fn (): string => __('tbtop-cms::profile.title'))
                ->url('/' . trim($prefix, '/') . '/profile')->icon('user'),
        ];
    }

    /**
     * One menu item per collection: CollectionEntriesPage takes the
     * collection from its URL, so it has no menu item of its own. None when
     * a host config leaves the page out.
     *
     * @return list<NavItem>
     */
    private function collectionItems(string $prefix): array
    {
        if (! in_array(CollectionEntriesPage::class, $this->pages(), true)) {
            return [];
        }

        $items = [];
        foreach (app(CollectionRegistry::class)->all() as $collection) {
            $items[] = NavItem::make(static fn (): string => $collection->label())
                ->url('/' . trim($prefix, '/') . '/collections/' . $collection->key())
                ->group($collection->navigationGroup())
                ->sort($collection->navigationOrder())
                ->icon($collection->icon());
        }

        return $items;
    }

    /** @return list<class-string<Page>> */
    private function pages(): array
    {
        /** @var array<string, class-string<Page>|null> $pages */
        $pages = (array) config('tbtop-cms.admin.pages', []);

        return array_values(array_filter($pages));
    }
}
