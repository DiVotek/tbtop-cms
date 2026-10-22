<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Support;

use Illuminate\Database\Eloquent\Model;
use Tbtop\Admin\Panels\PanelConfig;
use Tbtop\Admin\Panels\PanelRegistry;
use Tbtop\Cms\Admin\Models\CmsUserProfile;

/**
 * The admin UI locales — the cms panel's `locales()`, which the profile's
 * locale select offers — read from the panel registry so code outside a
 * panel request (a staff notification sent after a frontend form submit)
 * sees the same list. Not the content languages: those are what pages are
 * written in, not what the admin reads.
 */
final class AdminLocales
{
    private const string PANEL_ID = 'cms';

    /** @return list<string> */
    public static function all(): array
    {
        $locales = self::panel()?->getLocales() ?? [];

        return $locales === [] ? [self::default()] : $locales;
    }

    public static function default(): string
    {
        return self::panel()?->getDefaultLocale() ?? 'en';
    }

    /**
     * Each admin's UI locale keyed by user key: the locale stored on their
     * profile when the panel still offers it, else the panel default.
     *
     * @param  iterable<Model>  $admins
     * @return array<array-key, string>
     */
    public static function forAdmins(iterable $admins): array
    {
        $keys = [];
        foreach ($admins as $admin) {
            $keys[] = $admin->getKey();
        }

        $stored = CmsUserProfile::query()->whereIn('user_id', $keys)->pluck('locale', 'user_id');
        $available = self::all();
        $default = self::default();

        $locales = [];
        foreach ($keys as $key) {
            $locale = $stored->get($key);
            $locales[$key] = is_string($locale) && in_array($locale, $available, true) ? $locale : $default;
        }

        return $locales;
    }

    private static function panel(): ?PanelConfig
    {
        $panels = app(PanelRegistry::class)->all();

        return $panels[self::PANEL_ID] ?? null;
    }
}
