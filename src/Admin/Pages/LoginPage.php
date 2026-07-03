<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page;
use Tbtop\Admin\Panels\PanelConfig;

class LoginPage extends Page
{
    public static function path(): string
    {
        return 'login';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return null;
    }

    /** @return list<string>|null */
    public static function middleware(PanelConfig $panel): ?array
    {
        return ['web', 'guest:' . $panel->getGuard()];
    }

    #[\Override]
    public function title(): string
    {
        return 'Sign in';
    }

    #[\Override]
    public function layout(): string
    {
        return 'center';
    }

    public function view(S $s): Node
    {
        $form = $s->form('login', [
            $s->text('email')->label('Email')->required()->rules('email'),
            $s->password('password')->label('Password')->required(),
            $s->checkbox('remember')->label('Remember me'),
            $s->actionsRow([
                $s->action('signin')->label('Sign in')->color('primary')->submit(),
            ]),
        ])->onSubmit($this->authenticate(...));

        return $s->stack([
            $s->displayText('Sign in')->variant('heading'),
            $form,
        ]);
    }

    private function authenticate(ActionCtx $ctx): Effects
    {
        $guard = (string) config('tbtop-cms.admin.guard', 'web');
        $remember = (bool) ($ctx->form['remember'] ?? false);
        $email = (string) ($ctx->form['email'] ?? '');

        $throttleKey = Str::lower($email) . '|' . $ctx->request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => $seconds]),
            ]);
        }

        $ok = auth()->guard($guard)->attempt([
            'email' => $email,
            'password' => $ctx->form['password'] ?? '',
        ], $remember);

        if (! $ok) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($throttleKey);
        $ctx->request->session()->regenerate();
        $prefix = (string) config('tbtop-cms.admin.prefix', 'admin');

        return Effects::make()->redirect(url($prefix . '/dashboard'));
    }
}
