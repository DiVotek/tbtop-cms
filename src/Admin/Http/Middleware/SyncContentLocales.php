<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tbtop\Cms\Admin\ContentLocales;
use Tbtop\Cms\Language\Languages;

class SyncContentLocales
{
    public function __construct(private readonly Languages $languages) {}

    public function handle(Request $request, Closure $next): Response
    {
        ContentLocales::sync($this->languages);

        return $next($request);
    }
}
