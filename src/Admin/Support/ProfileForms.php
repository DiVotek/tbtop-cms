<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Support;

use Closure;
use Tbtop\Admin\Dsl\FormBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;

/**
 * The three self-contained forms of the profile page tabs. Each tab
 * submits independently — mirrors the divotek profile, where a broken
 * call in one section never blocks the others. No cancel button:
 * there is no list to go back to from a self-service profile.
 */
final class ProfileForms
{
    public function __construct(private readonly S $s) {}

    /** @param array{display_name: string|null, email: string} $record */
    public function personal(array $record, Closure $onSubmit): FormBuilder
    {
        $s = $this->s;

        return $s->form('profile', [
            $s->text('display_name')->label(__('tbtop-cms::profile.fields.display_name'))->rules('nullable|max:255'),
            // The email belongs to the host app's user model — shown for
            // context, never editable from the cms profile.
            $s->text('email')->label(__('tbtop-cms::profile.fields.email'))->meta('disabled', true),
            $this->footer('save_profile', __('tbtop-cms::profile.actions.save')),
        ])->record($record)->onSubmit($onSubmit);
    }

    public function password(string $guard, Closure $onSubmit): FormBuilder
    {
        $s = $this->s;

        return $s->form('password', [
            $s->password('current_password')->label(__('tbtop-cms::profile.fields.current_password'))
                ->rules('required|current_password:' . $guard)->set('autoComplete', 'current-password'),
            $s->password('password')->label(__('tbtop-cms::profile.fields.new_password'))
                ->rules('required|min:8|confirmed|different:current_password')->set('autoComplete', 'new-password'),
            $s->password('password_confirmation')->label(__('tbtop-cms::profile.fields.confirm_password'))
                ->rules('required')->set('autoComplete', 'new-password'),
            $this->footer('update_password', __('tbtop-cms::profile.actions.update_password')),
        ])->onSubmit($onSubmit);
    }

    /**
     * @param  array{locale: string|null, theme: string}  $record
     * @param  list<string>  $locales
     */
    public function preferences(array $record, array $locales, Closure $onSubmit): FormBuilder
    {
        $s = $this->s;
        $localeOptions = array_map(
            static fn (string $locale): array => ['value' => $locale, 'label' => $locale],
            $locales,
        );
        $themeOptions = array_map(
            static fn (string $theme): array => [
                'value' => $theme,
                'label' => __('tbtop-cms::profile.theme.' . $theme),
            ],
            ProfileActions::THEMES,
        );

        return $s->form('preferences', [
            $s->select('locale')->label(__('tbtop-cms::profile.fields.locale'))
                ->options($localeOptions)->rules('nullable|in:' . implode(',', $locales)),
            $s->select('theme')->label(__('tbtop-cms::profile.fields.theme'))
                ->options($themeOptions)->rules('nullable|in:' . implode(',', ProfileActions::THEMES)),
            $this->footer('save_preferences', __('tbtop-cms::profile.actions.save')),
        ])->record($record)->onSubmit($onSubmit);
    }

    private function footer(string $name, string $label): Node
    {
        $s = $this->s;
        $save = $s->action($name)->label($label)->color('primary')->submit();

        return $s->flex([$s->spacer(), $save], justify: 'end');
    }
}
