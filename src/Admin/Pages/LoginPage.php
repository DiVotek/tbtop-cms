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
use Tbtop\Admin\Panels\CurrentPanel;
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
        // Browser <title> only — the card carries its own heading (translated,
        // no duplicate "Sign in") below.
        return __('tbtop-cms::login.title');
    }

    #[\Override]
    public function layout(): string
    {
        return 'center';
    }

    public function view(S $s): Node
    {
        $form = $s->form('login', [
            $s->text('email')->label(__('tbtop-cms::login.fields.email'))
                ->placeholder('admin@example.com')->required()->rules('email'),
            $s->password('password')->label(__('tbtop-cms::login.fields.password'))->required(),
            $s->checkbox('remember')->label(__('tbtop-cms::login.fields.remember')),
            // Bare stack (not actionsRow, which is flex-row/justify-between):
            // a stack's flex-col children default to items-stretch, so a lone
            // button child stretches full width for free — no width modifier
            // exists on ActionBuilder and none is needed.
            $s->stack([
                $s->action('signin')->label(__('tbtop-cms::login.actions.sign_in'))->color('primary')->submit(),
            ]),
        ])->onSubmit($this->authenticate(...));

        return $s->section(['variant' => 'card', 'class' => 'w-full max-w-sm mx-auto'], [
            // TextBlock (displayText's return type) has no class() escape hatch,
            // so centering wraps the brand/title/subtitle group in a stack instead.
            $s->stack([
                $s->displayText($this->brand())->variant('heading'),
                $s->displayText(__('tbtop-cms::login.title'))->variant('subheading'),
                $s->displayText(__('tbtop-cms::login.subtitle'))->variant('muted'),
            ], ['class' => 'text-center gap-1']),
            $form,
        ]);
    }

    private function brand(): string
    {
        // Same fallback chain as the topbar logo (chromeBlocks: brand ?? nav.title),
        // so the login card and the shell never show different product names.
        return CurrentPanel::current()?->brand() ?? __('tbtop-admin::admin.nav.title');
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
