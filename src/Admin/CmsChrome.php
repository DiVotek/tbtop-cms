<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin;

use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Color;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Panels\Chrome;
use Tbtop\Cms\Form\Models\FormSubmission;

/**
 * CMS shell chrome for the full-width top bar layout. The brand and
 * sidebar toggle live in the bar itself, so the sidebar holds only the
 * nav. The header carries theme, locale, notifications, a live-site link
 * and the profile menu. All blocks are framework built-ins — no client code.
 */
class CmsChrome extends Chrome
{
    /**
     * Sidebar items — nav only; the frame renders the brand in the top bar.
     *
     * @return list<mixed>
     */
    protected function sidebarItems(S $s): array
    {
        return [$s->navMenu()];
    }

    /**
     * Header, left to right (the shell right-aligns the row):
     * theme · locale · notifications · inbox · view-site · profile.
     *
     * @return list<mixed>
     */
    protected function headerItems(S $s): array
    {
        return [
            $s->themeToggle(),
            $s->localeSwitcher(),
            $s->notifications(),
            $this->inbox($s),
            $s->action('view-site')
                ->label('View site')
                ->icon('external-link')
                ->tooltip('Open the live site')
                ->size('sm')
                ->visit('/', newTab: true),
            $s->userMenu(),
        ];
    }

    /** Inbox link with an unread-lead count badge. */
    private function inbox(S $s): ActionBuilder
    {
        $unread = FormSubmission::query()->unread()->count();
        $action = $s->action('inbox')
            ->label('Inbox')
            ->icon('inbox')
            ->tooltip('Form submissions')
            ->size('sm')
            ->visit('/' . trim((string) config('tbtop-cms.admin.prefix', 'admin'), '/') . '/form-submissions');

        if ($unread > 0) {
            $action->badge($unread, Color::Danger);
        }

        return $action;
    }
}
