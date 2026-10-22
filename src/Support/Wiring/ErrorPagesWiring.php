<?php

declare(strict_types=1);

namespace Tbtop\Cms\Support\Wiring;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tbtop\Cms\Cms;
use Tbtop\Cms\NotFound\Events\PageNotFound;
use Tbtop\Cms\NotFound\Listeners\RecordsNotFoundHit;

/**
 * Frontend error handling: the 404 log listener and the exception-handler hook
 * that renders cms error pages.
 *
 * Called from CmsServiceProvider::packageBooted().
 */
final class ErrorPagesWiring
{
    public static function wire(Application $app): void
    {
        self::wireNotFoundLogging();
        self::wireErrorPages($app);
    }

    private static function wireNotFoundLogging(): void
    {
        Event::listen(PageNotFound::class, RecordsNotFoundHit::class);
    }

    /**
     * Renders the matching error page for a whitelisted status instead of
     * Laravel's stock error view — gated by config so a host can opt out
     * entirely (errors.handle=false) and still call Cms::errorResponse()
     * itself. Registered against HttpExceptionInterface, not Throwable: a
     * non-HTTP exception only reaches here after Laravel's own handler has
     * already converted it (see Handler::prepareException()), so this never
     * duplicates that conversion.
     */
    private static function wireErrorPages(Application $app): void
    {
        $app->afterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            $handler->renderable(function (HttpExceptionInterface $e, Request $request): ?Response {
                return self::renderError($request, $e->getStatusCode());
            });
        });
    }

    private static function renderError(Request $request, int $status): ?Response
    {
        if (! (bool) config('tbtop-cms.errors.handle', true)) {
            return null;
        }

        /** @var list<int> $statuses */
        $statuses = (array) config('tbtop-cms.errors.statuses', [404, 500, 503]);

        if (! in_array($status, $statuses, true)) {
            return null;
        }

        if ($status >= 500 && (bool) config('app.debug', false)) {
            return null;
        }

        // API clients keep Laravel's JSON error body; Inertia XHRs send
        // Accept: text/html, so they are not caught by this check.
        if ($request->expectsJson()) {
            return null;
        }

        $prefix = (string) config('tbtop-cms.admin.prefix', 'admin');

        if ($request->is($prefix) || $request->is($prefix . '/*')) {
            return null;
        }

        return app(Cms::class)->errorResponse($request, $status);
    }
}
