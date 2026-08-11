<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin;

use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Color;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Panels\Chrome;
use Tbtop\Cms\Admin\Support\CmsAbilities;
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
    #[\Override]
    protected function sidebarItems(S $s): array
    {
        return [$s->navMenu()];
    }

    /**
     * Header, left to right (the shell right-aligns the row):
     * notifications · inbox · view-site · profile.
     * No theme toggle or locale switcher: both are profile
     * preferences; standalone copies only ate top-bar space.
     *
     * @return list<mixed>
     */
    #[\Override]
    protected function headerItems(S $s): array
    {
        return [
            $s->notifications(),
            $this->inbox($s),
            $s->action('view-site')
                ->label(__('tbtop-cms::chrome.view_site.label'))
                ->icon('external-link')
                ->tooltip(__('tbtop-cms::chrome.view_site.tooltip'))
                ->size('sm')
                ->visit('/', newTab: true),
            $s->userMenu(['locales' => false]),
        ];
    }

    /** Inbox link with an unread-lead count badge. */
    private function inbox(S $s): ActionBuilder
    {
        $unread = FormSubmission::query()->unread()->count();
        $action = $s->action('inbox')
            ->label(__('tbtop-cms::chrome.inbox.label'))
            ->icon('inbox')
            ->tooltip(__('tbtop-cms::chrome.inbox.tooltip'))
            ->size('sm')
            ->visit('/' . trim((string) config('tbtop-cms.admin.prefix', 'admin'), '/') . '/form-submissions');

        // authorize() takes a non-null string and an undefined gate denies —
        // with roles off, calling it unconditionally would hide the inbox
        // for everyone, since the package defines no gates itself.
        if (CmsAbilities::enabled()) {
            $action->authorize('tbtop-cms.submissions');
        }

        if ($unread > 0) {
            $action->badge($unread, Color::Danger);
        }

        return $action;
    }
}
