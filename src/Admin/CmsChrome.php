<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin;

use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Panels\Chrome;

/**
 * CMS shell chrome. Keeps the stock sidebar; enriches the header with a
 * locale switcher, notifications bell, a link back to the live site and
 * the profile menu. All blocks are framework built-ins — no client code.
 */
class CmsChrome extends Chrome
{
    /**
     * Header, left to right (the shell right-aligns the row):
     * theme · locale · notifications · view-site · profile.
     *
     * @return list<mixed>
     */
    protected function headerItems(S $s): array
    {
        return [
            $s->themeToggle(),
            $s->localeSwitcher(),
            $s->notifications(),
            $s->action('view-site')
                ->label('View site')
                ->icon('external-link')
                ->tooltip('Open the live site')
                ->size('sm')
                ->visit('/', newTab: true),
            $s->userMenu(),
        ];
    }
}
