<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
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
            'defaultLocale' => $this->languages->defaultLocale(),
            'languages' => $this->frontendLanguages(),
        ]);

        Inertia::share('errors', Inertia::always($this->validationErrors($request)));

        return $next($request);
    }

    /** @return list<array{locale: string, name: string, is_default: bool}> */
    private function frontendLanguages(): array
    {
        return array_map(
            static fn (array $row): array => [
                'locale' => $row['locale'],
                'name' => $row['name'],
                'is_default' => $row['is_default'],
            ],
            $this->languages->frontendActive(),
        );
    }

    private function validationErrors(Request $request): object
    {
        if (! $request->hasSession()) {
            return (object) [];
        }

        $bag = $request->session()->get('errors');

        if (! $bag instanceof ViewErrorBag) {
            return (object) [];
        }

        return (object) array_map(
            static fn (array $messages): string => $messages[0],
            $bag->getBag('default')->getMessages(),
        );
    }
}
