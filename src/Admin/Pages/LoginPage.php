<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page;
use Tbtop\Admin\Panels\CurrentPanel;
use Tbtop\Admin\Panels\PanelConfig;
use Tbtop\Cms\Admin\Http\Middleware\RedirectIfCmsAuthenticated;
use Tbtop\Cms\Admin\Support\CurrentAdmin;

// Deliberately no can() override: must be reachable unauthenticated, gated
// by the guest-redirect middleware below instead. See SectionAbilitiesTest.
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
        return ['web', RedirectIfCmsAuthenticated::class . ':' . $panel->getGuard()];
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
                ->required()->rules('email'),
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

        // Fixed width, not w-full: the center-layout wrapper shrink-wraps its
        // content, so a percentage width breathes with it — the card widened
        // whenever a long validation message appeared and snapped back once it
        // cleared. max-w-full keeps small viewports usable.
        $card = $s->section(['variant' => 'card', 'class' => 'w-96 max-w-full mx-auto shadow-md'], [
            // Outer stack spaces the header away from the form; "Sign in" only
            // appears once on screen now (the button), so the header carries
            // just the brand heading plus the muted subtitle.
            $s->stack([
                // TextBlock (displayText's return type) has no class() escape hatch,
                // so centering wraps the brand/subtitle group in a stack instead.
                $s->stack([
                    $s->displayText($this->brand())->variant('heading'),
                    $s->displayText(__('tbtop-cms::login.subtitle'))->variant('muted'),
                ], ['class' => 'text-center gap-1']),
                $form,
            ], ['class' => 'gap-6']),
        ]);

        $siteLink = sprintf(
            '<a href="%s" class="block text-center text-sm text-muted-foreground hover:text-foreground">&larr; %s</a>',
            e(url('/')),
            e(__('tbtop-cms::login.links.site')),
        );

        return $s->stack([
            // Placeholder product mark until a real logo lands in the panel
            // config; inline SVG with currentColor so it follows the theme.
            $s->displayHtml(self::LOGO_SVG),
            $card,
            $s->displayHtml($siteLink),
            // Pinned to the viewport bottom, outside the centered flow; opaque
            // background so content scrolling beneath stays readable. The
            // outer stack's pb-14 reserves matching space in the flow so the
            // footer never covers the site link on short viewports.
            $s->stack([
                $s->displayText($this->footerLine())->variant('muted'),
            ], ['class' => 'fixed bottom-0 inset-x-0 py-4 text-center bg-background']),
        ], ['class' => 'gap-6 pb-14']);
    }

    /**
     * A d6 die in the lucide line style — the tabletop nod. Stroke inherits
     * currentColor, so it stays visible in both themes.
     */
    private const LOGO_SVG = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mx-auto h-12 w-12 text-foreground" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><path d="M16 8h.01"/><path d="M8 8h.01"/><path d="M8 16h.01"/><path d="M16 16h.01"/><path d="M12 12h.01"/></svg>
        SVG;

    private function footerLine(): string
    {
        $line = 'TableTop CMS © ' . date('Y');

        // Only tagged installs read as a version ("ver. 1.2.3"); dev installs
        // report a branch name (dev-main), which means nothing on a support
        // screenshot, so the footer drops the version part entirely.
        $version = (string) (InstalledVersions::isInstalled('tbtop/cms')
            ? InstalledVersions::getPrettyVersion('tbtop/cms')
            : '');
        if (preg_match('/^v?\d+(\.\d+)+$/', $version) === 1) {
            $line .= ' · ver. ' . ltrim($version, 'v');
        }

        return $line;
    }

    private function brand(): string
    {
        // Same fallback chain as the topbar logo (chromeBlocks: brand ?? nav.title),
        // so the login card and the shell never show different product names.
        return CurrentPanel::current()?->brand() ?? __('tbtop-admin::admin.nav.title');
    }

    private function authenticate(ActionCtx $ctx): string
    {
        $guard = (string) config('tbtop-cms.admin.guard', 'web');
        $remember = (bool) ($ctx->form['remember'] ?? false);
        $email = (string) ($ctx->form['email'] ?? '');

        $throttleKey = Str::lower($email) . '|' . $ctx->request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => __('tbtop-cms::login.errors.throttle', ['seconds' => $seconds]),
            ]);
        }

        $ok = auth()->guard($guard)->attempt([
            'email' => $email,
            'password' => $ctx->form['password'] ?? '',
        ], $remember);

        if (! $ok) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages(['email' => __('tbtop-cms::login.errors.failed')]);
        }

        $authenticated = auth()->guard($guard)->user();
        if ($authenticated === null || ! CurrentAdmin::allows($authenticated)) {
            auth()->guard($guard)->logout();
            $ctx->request->session()->invalidate();
            $ctx->request->session()->regenerateToken();
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages(['email' => __('tbtop-cms::login.errors.failed')]);
        }

        RateLimiter::clear($throttleKey);
        $ctx->request->session()->regenerate();
        $prefix = (string) config('tbtop-cms.admin.prefix', 'admin');

        return url($prefix . '/dashboard');
    }
}
