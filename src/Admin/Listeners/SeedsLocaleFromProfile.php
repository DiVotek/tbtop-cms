<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Listeners;

use Illuminate\Auth\Events\Login;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Support\CurrentAdmin;

/**
 * The admin locale switcher (SetAdminLocale middleware, tbtop/admin) reads
 * only session('tbtop.locale') per request — see LocaleService::currentLocale().
 * ProfileActions::preferences() keeps that key in sync while a session is
 * live, but a fresh login starts a new session with nothing in it, so the
 * panel falls back to English until the user re-visits Preferences. This
 * seeds the session from the stored profile once, right after login.
 */
final class SeedsLocaleFromProfile
{
    public function handle(Login $event): void
    {
        if ($event->guard !== CurrentAdmin::guard()) {
            return;
        }

        $profile = CmsUserProfile::query()
            ->where('user_id', $event->user->getAuthIdentifier())
            ->first();

        if ($profile !== null && is_string($profile->locale) && $profile->locale !== '') {
            session(['tbtop.locale' => $profile->locale]);
        }
    }
}
