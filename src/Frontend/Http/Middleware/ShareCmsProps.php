<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Settings\Settings;

class ShareCmsProps
{
    public function __construct(
        private readonly Languages $languages,
        private readonly Settings $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $rootView = config('tbtop-cms.render.root_view');

        if (is_string($rootView) && $rootView !== '') {
            Inertia::setRootView($rootView);
        }

        Inertia::share('cms', fn (): array => [
            'settings' => $this->settings->forFrontend(),
            'locale' => (string) app()->getLocale(),
            'languages' => $this->frontendLanguages(),
        ]);

        return $next($request);
    }

    /** @return list<array{locale: string, name: string}> */
    private function frontendLanguages(): array
    {
        return array_map(
            static fn (array $row): array => ['locale' => $row['locale'], 'name' => $row['name']],
            $this->languages->frontendActive(),
        );
    }
}
