<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Inertia\Ssr\Gateway;
use Inertia\Ssr\Response as SsrResponse;
use Tbtop\Cms\Frontend\Http\Middleware\EnableSsr;

/**
 * Test double for Inertia\Ssr\Gateway. Counts dispatch() calls (to prove the
 * request-scoped SsrState memo collapses the @inertia/@inertiaHead/head
 * include's three call sites into one underlying dispatch) and returns a
 * canned response, or null to simulate the SSR process being down.
 *
 * Mirrors HttpGateway's request gate: SsrState::dispatch()
 * (called unconditionally by @inertia/@inertiaHead, regardless of any flag)
 * always reaches Gateway::dispatch() — the real gateway is what short-circuits
 * on an unmarked request before doing any actual work. A fake that always
 * recorded a call would make "the gateway is never called" assertions
 * meaningless; this one only counts a call when SSR is actually enabled,
 * same as production.
 */
class RecordingSsrGateway implements Gateway
{
    public static int $calls = 0;

    public static ?SsrResponse $response = null;

    public static function reset(): void
    {
        self::$calls = 0;
        self::$response = null;
    }

    /** @param  array<string, mixed>  $page */
    public function dispatch(array $page): ?SsrResponse
    {
        if (! request()->attributes->get(EnableSsr::REQUEST_ATTRIBUTE, false)) {
            return null;
        }

        self::$calls++;

        return self::$response;
    }
}
