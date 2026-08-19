<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\I18n\LocaleService;
use Tbtop\Admin\Pages\Page;
use Tbtop\Cms\Admin\Support\CurrentAdmin;
use Tbtop\Cms\Admin\Support\ProfileActions;
use Tbtop\Cms\Admin\Support\ProfileForms;

class ProfilePage extends Page
{
    public static function path(): string
    {
        return 'profile';
    }

    /**
     * Hidden from the sidebar — reached via the top-bar profile menu.
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
        return __('tbtop-cms::profile.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::profile.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::profile.title')],
        ];
    }

    /**
     * Divotek-style profile: three tabs, each with its own form and
     * submit, so one failing section never blocks the others.
     */
    public function view(S $s): Node
    {
        $profile = CurrentAdmin::profile();
        $forms = new ProfileForms($s);
        $actions = new ProfileActions;

        $tabs = [
            [
                'label' => __('tbtop-cms::profile.sections.personal'),
                'body' => $forms->personal(
                    ['display_name' => $profile->display_name, 'email' => CurrentAdmin::email()],
                    $actions->personal(...),
                ),
            ],
        ];

        // SSO / passwordless hosts disable the password tab via config.
        if ((bool) config('tbtop-cms.profile.password_change', true)) {
            $tabs[] = [
                'label' => __('tbtop-cms::profile.sections.password'),
                'body' => $forms->password(CurrentAdmin::guard(), $actions->password(...)),
            ];
        }

        // A fresh profile stores no locale; show the one the panel actually
        // renders in, so the select is never blank and Save round-trips it.
        $tabs[] = [
            'label' => __('tbtop-cms::profile.sections.preferences'),
            'body' => $forms->preferences(
                ['locale' => $profile->locale ?? LocaleService::currentLocale(), 'theme' => $this->currentTheme()],
                LocaleService::availableLocales(),
                $actions->preferences(...),
            ),
        ];

        // Compact column like the divotek profile — the panel itself
        // runs full-width, so the page narrows its own content.
        return $s->stack([$s->tabs($tabs)], ['class' => 'mx-auto w-full max-w-2xl']);
    }

    /** The theme cookie is the source of truth — the client applies it. */
    private function currentTheme(): string
    {
        $theme = (string) request()->cookie('tbtop_theme', 'system');

        return in_array($theme, ProfileActions::THEMES, true) ? $theme : 'system';
    }
}
