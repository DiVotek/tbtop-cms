<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\I18n\LocaleService;
use Tbtop\Admin\Pages\Page;
use Tbtop\Cms\Admin\Models\CmsUserProfile;

class ProfilePage extends Page
{
    public static function path(): string
    {
        return 'profile';
    }

    /**
     * Reached from the profile menu in the top bar, not the sidebar nav.
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
        return 'Profile';
    }

    public function view(S $s): Node
    {
        $profile = $this->profile();
        $locales = LocaleService::availableLocales();
        $options = array_map(
            static fn (string $locale): array => ['value' => $locale, 'label' => $locale],
            $locales,
        );

        $form = $s->form('profile', [
            $s->text('display_name')->label('Display name')->rules('nullable|max:255'),
            $s->select('locale')->label('Language')->options($options)->rules('nullable|in:' . implode(',', $locales)),
            $s->media('avatar_media_id')->label('Avatar'),
            $s->actionsRow([
                $s->action('save')->label('Save')->color('primary')->keybinding('mod+s')->submit(),
            ]),
        ])
            ->record([
                'display_name' => $profile->display_name,
                'locale' => $profile->locale,
                'avatar_media_id' => $profile->avatar_media_id,
            ])
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
    }

    private function save(ActionCtx $ctx): Effects
    {
        $profile = $this->profile();
        $profile->forceFill([
            'display_name' => $ctx->form['display_name'] ?? null,
            'locale' => $ctx->form['locale'] ?? null,
            'avatar_media_id' => $ctx->form['avatar_media_id'] ?? null,
        ])->save();

        if (is_string($profile->locale) && $profile->locale !== '') {
            session(['tbtop.locale' => $profile->locale]);
        }

        return Effects::make()->notify('Saved');
    }

    private function profile(): CmsUserProfile
    {
        $guard = (string) config('tbtop-cms.admin.guard', 'web');
        $userId = auth()->guard($guard)->user()?->getAuthIdentifier();

        return CmsUserProfile::query()->firstOrNew(['user_id' => $userId]);
    }
}
