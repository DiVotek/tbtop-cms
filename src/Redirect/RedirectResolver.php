<?php

declare(strict_types=1);

namespace Tbtop\Cms\Redirect;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Tbtop\Cms\Page\PageUrl;
use Tbtop\Cms\Redirect\Models\Redirect;
use Tbtop\Cms\Redirect\Support\RedirectPath;

class RedirectResolver
{
    private const int MAX_HOPS = 10;

    public function __construct(private readonly PageUrl $pageUrl) {}

    public function resolve(string $path, string $locale, Request $request): ?RedirectResponse
    {
        $from = RedirectPath::normalize($path);
        $redirect = $this->match($from, $locale);

        if (! $redirect instanceof Redirect) {
            return null;
        }

        $target = $this->follow($redirect, $from, $locale);

        if ($target === null) {
            return null;
        }

        $url = RedirectPath::isExternal($target) ? $target : $this->pageUrl->build($target, $locale);

        return redirect()->to($this->withQuery($url, $request), $redirect->status_code);
    }

    private function match(string $from, string $locale): ?Redirect
    {
        $specific = Redirect::query()->where('locale', $locale)->where('from_path', $from)->first();

        return $specific ?? Redirect::query()->whereNull('locale')->where('from_path', $from)->first();
    }

    private function follow(Redirect $redirect, string $from, string $locale): ?string
    {
        $seen = [$from];

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            if (RedirectPath::isExternal($redirect->to_path)) {
                return $redirect->to_path;
            }

            $to = RedirectPath::normalize($redirect->to_path);

            if (in_array($to, $seen, true)) {
                return null;
            }

            $next = $this->match($to, $locale);

            if (! $next instanceof Redirect) {
                return $to;
            }

            $seen[] = $to;
            $redirect = $next;
        }

        return null;
    }

    private function withQuery(string $url, Request $request): string
    {
        $query = $request->getQueryString();

        if ($query === null || $query === '' || str_contains($url, '?')) {
            return $url;
        }

        return $url . '?' . $query;
    }
}
