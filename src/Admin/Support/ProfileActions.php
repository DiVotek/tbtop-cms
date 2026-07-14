<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Support;

use Illuminate\Support\Facades\Hash;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;

/**
 * Submit handlers for the profile page forms. Password change writes
 * to the host app's user model; the DSL rules on the password form
 * (current_password guard check, confirmed, min length) already ran
 * in FormSubmitController before any handler is invoked.
 */
final class ProfileActions
{
    public function personal(ActionCtx $ctx): Effects
    {
        CurrentAdmin::profile()->forceFill([
            'display_name' => $ctx->form['display_name'] ?? null,
        ])->save();

        return Effects::make()->notify(__('tbtop-cms::profile.actions.saved'));
    }

    public function password(ActionCtx $ctx): Effects
    {
        $user = CurrentAdmin::user();
        abort_if($user === null, 401);

        // Hash::make is safe with a 'hashed' cast on the host model —
        // Laravel 11+ casts skip values that are already hashed.
        $user->forceFill([
            'password' => Hash::make((string) $ctx->form['password']),
        ])->save();

        return Effects::make()->notify(__('tbtop-cms::profile.actions.password_updated'));
    }

    public function preferences(ActionCtx $ctx): Effects
    {
        $profile = CurrentAdmin::profile();
        $profile->forceFill(['locale' => $ctx->form['locale'] ?? null])->save();

        if (is_string($profile->locale) && $profile->locale !== '') {
            session(['tbtop.locale' => $profile->locale]);
        }

        return Effects::make()->notify(__('tbtop-cms::profile.actions.saved'));
    }
}
