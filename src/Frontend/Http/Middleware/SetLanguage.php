<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Middleware;

use Closure;
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

        if ($first !== '' && $first !== $default && $this->isFrontendLocale($first)) {
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
}
