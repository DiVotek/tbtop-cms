<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin;

use Tbtop\Admin\Pages\Page;
use Tbtop\Admin\Panels\Panel;
use Tbtop\Admin\Panels\PanelConfig;
use Tbtop\Cms\Admin\Http\Middleware\EnsureCmsAdmin;

class CmsPanel extends Panel
{
    public function configure(PanelConfig $panel): PanelConfig
    {
        return $panel
            ->id('cms')
            ->prefix((string) config('tbtop-cms.admin.prefix', 'admin'))
            ->guard((string) config('tbtop-cms.admin.guard', 'web'))
            ->middleware(['web', EnsureCmsAdmin::class])
            ->pages($this->pages());
    }

    /** @return list<class-string<Page>> */
    private function pages(): array
    {
        /** @var array<string, class-string<Page>|null> $pages */
        $pages = (array) config('tbtop-cms.admin.pages', []);

        return array_values(array_filter($pages));
    }
}
