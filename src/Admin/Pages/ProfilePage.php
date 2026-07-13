<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\I18n\LocaleService;
use Tbtop\Admin\Pages\Page;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Support\EditFooter;

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

    public function view(S $s): Node
    {
        $profile = $this->profile();
        $locales = LocaleService::availableLocales();
        $options = array_map(
            static fn (string $locale): array => ['value' => $locale, 'label' => $locale],
            $locales,
        );

        // No profile list to cancel back to, like SettingsPageBase's singleton
        // pages — cancel reloads this same page.
        $footer = EditFooter::build($s, $this->selfUrl(), $this->saveAction($s));

        $form = $s->form('profile', [
            $s->section(['title' => __('tbtop-cms::profile.sections.personal'), 'variant' => 'card'], [
                $s->text('display_name')->label(__('tbtop-cms::profile.fields.display_name'))->rules('nullable|max:255'),
                // The email belongs to the host app's user model — shown for
                // context, never editable from the cms profile.
                $s->text('email')->label(__('tbtop-cms::profile.fields.email'))->meta('disabled', true),
            ]),
            $s->section(['title' => __('tbtop-cms::profile.sections.preferences'), 'variant' => 'card'], [
                $s->select('locale')->label(__('tbtop-cms::profile.fields.locale'))
                    ->options($options)->rules('nullable|in:' . implode(',', $locales)),
            ]),
            $footer,
        ])
            ->record([
                'display_name' => $profile->display_name,
                'email' => $this->userEmail(),
                'locale' => $profile->locale,
            ])
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
    }

    private function saveAction(S $s): ActionBuilder
    {
        return $s->action('save')->label(__('tbtop-cms::profile.actions.save'))
            ->color('primary')->keybinding('mod+s')->submit();
    }

    private function save(ActionCtx $ctx): Effects
    {
        $profile = $this->profile();
        $profile->forceFill([
            'display_name' => $ctx->form['display_name'] ?? null,
            'locale' => $ctx->form['locale'] ?? null,
        ])->save();

        if (is_string($profile->locale) && $profile->locale !== '') {
            session(['tbtop.locale' => $profile->locale]);
        }

        return Effects::make()->notify(__('tbtop-cms::profile.actions.saved'));
    }

    private function selfUrl(): string
    {
        return route('tbtop.cms.' . static::slug());
    }

    private function profile(): CmsUserProfile
    {
        $guard = (string) config('tbtop-cms.admin.guard', 'web');
        $userId = auth()->guard($guard)->user()?->getAuthIdentifier();

        return CmsUserProfile::query()->firstOrNew(['user_id' => $userId]);
    }

    private function userEmail(): string
    {
        $guard = (string) config('tbtop-cms.admin.guard', 'web');
        $user = auth()->guard($guard)->user();

        return is_object($user) && isset($user->email) ? (string) $user->email : '';
    }
}
