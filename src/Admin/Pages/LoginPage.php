<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Composer\InstalledVersions;
use Illuminate\Http\Request;
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
use Tbtop\Cms\Settings\Settings;

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
                    $s->displayText($this->heading())->variant('heading'),
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
            // The product mark: tbtop.dev's hero tiles (t·b·t·o·p) over the product
            // name, the name stretched to the tiles' width. Inline SVG; the name
            // uses currentColor so it follows the theme.
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

    private const LOGO_SVG = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 212 68" class="mx-auto h-16 w-auto text-foreground" role="img" aria-label="TableTop CMS" font-family="ui-sans-serif, system-ui, sans-serif"><defs><linearGradient id="tt0" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8b5cf6"/><stop offset="1" stop-color="#6d28d9"/></linearGradient><linearGradient id="tt1" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f472b6"/><stop offset="1" stop-color="#db2777"/></linearGradient><linearGradient id="tt2" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f5b83d"/><stop offset="1" stop-color="#d97706"/></linearGradient><linearGradient id="tt3" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#a3e635"/><stop offset="1" stop-color="#65a30d"/></linearGradient><linearGradient id="tt4" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#22d3ee"/><stop offset="1" stop-color="#0891b2"/></linearGradient><linearGradient id="ttgloss" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".42"/><stop offset=".4" stop-color="#fff" stop-opacity="0"/></linearGradient></defs><g><rect x="0" y="0" width="36" height="36" rx="8" fill="url(#tt0)"/><rect x="0" y="0" width="36" height="36" rx="8" fill="url(#ttgloss)"/><text x="18" y="26" text-anchor="middle" font-size="28" font-weight="800" fill="#0a0a12">t</text><rect x="44" y="0" width="36" height="36" rx="8" fill="url(#tt1)"/><rect x="44" y="0" width="36" height="36" rx="8" fill="url(#ttgloss)"/><text x="62" y="26" text-anchor="middle" font-size="28" font-weight="800" fill="#0a0a12">b</text><rect x="88" y="0" width="36" height="36" rx="8" fill="url(#tt2)"/><rect x="88" y="0" width="36" height="36" rx="8" fill="url(#ttgloss)"/><text x="106" y="26" text-anchor="middle" font-size="28" font-weight="800" fill="#0a0a12">t</text><rect x="132" y="0" width="36" height="36" rx="8" fill="url(#tt3)"/><rect x="132" y="0" width="36" height="36" rx="8" fill="url(#ttgloss)"/><text x="150" y="26" text-anchor="middle" font-size="28" font-weight="800" fill="#0a0a12">o</text><rect x="176" y="0" width="36" height="36" rx="8" fill="url(#tt4)"/><rect x="176" y="0" width="36" height="36" rx="8" fill="url(#ttgloss)"/><text x="194" y="26" text-anchor="middle" font-size="28" font-weight="800" fill="#0a0a12">p</text></g><text x="0" y="64" textLength="212" lengthAdjust="spacing" font-size="24" font-weight="700" fill="currentColor">TableTop CMS</text></svg>
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

    /** The site's name from Site settings — which project this login opens — or the product brand until it is set. */
    private function heading(): string
    {
        $siteName = trim((string) app(Settings::class)->get('site.name', ''));

        return $siteName !== '' ? $siteName : $this->brand();
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

        return $this->redirectTarget($ctx->request);
    }

    /**
     * Where the auth guard sent the guest from (session `url.intended`) when
     * that is an admin URL on this host, else the dashboard. Pulled, so a
     * stale target never outlives this sign-in.
     */
    private function redirectTarget(Request $request): string
    {
        $admin = url((string) config('tbtop-cms.admin.prefix', 'admin'));
        $intended = $request->session()->pull('url.intended');

        if (is_string($intended) && self::isUnder($intended, $admin)) {
            return $intended;
        }

        return $admin . '/dashboard';
    }

    /** `$url` is `$base` itself or a path, query or fragment below it — `/administrator` is not under `/admin`. */
    private static function isUnder(string $url, string $base): bool
    {
        if (! str_starts_with($url, $base)) {
            return false;
        }

        $next = substr($url, strlen($base), 1);

        return in_array($next, ['', '/', '?', '#'], true);
    }
}
