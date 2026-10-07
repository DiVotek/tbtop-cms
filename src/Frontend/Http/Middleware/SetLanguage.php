<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Cms\Language\Languages;

class SetLanguage
{
    public function __construct(private readonly Languages $languages) {}

    public function handle(Request $request, Closure $next): Response
    {
        $path = trim((string) $request->route('path'), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $first = $segments[0] ?? '';
        $default = $this->languages->defaultLocale();

        if ($first !== '' && $first === $default) {
            return $this->canonical($segments, $request);
        }

        if ($first !== '' && $this->isFrontendLocale($first)) {
            $this->languages->setCurrent($first);
            array_shift($segments);
        } elseif ($default !== '') {
            $this->languages->setCurrent($default);
        }

        $request->attributes->set('cms_path', implode('/', $segments));

        return $next($request);
    }

    private function isFrontendLocale(string $locale): bool
    {
        return array_any($this->languages->frontendActive(), fn (array $row): bool => $row['locale'] === $locale);
    }

    /** @param list<string> $segments */
    private function canonical(array $segments, Request $request): RedirectResponse
    {
        array_shift($segments);
        // Empty segments ("/en//evil.example") would glue into "//evil.example",
        // which redirect()->to() passes through as a protocol-relative URL to
        // another host.
        $url = '/' . implode('/', array_filter($segments, static fn (string $s): bool => $s !== ''));
        $query = $request->getQueryString();

        return redirect()->to($query !== null && $query !== '' ? $url . '?' . $query : $url, 301);
    }
}
