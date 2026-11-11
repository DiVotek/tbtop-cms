<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Controllers;

use Illuminate\Http\Response;
use Tbtop\Cms\Frontend\Seo\IndexNow\IndexNowSettings;

/**
 * `/{key}.txt`: the file IndexNow fetches to check that a submission came
 * from the site. Only the stored key answers, and only while IndexNow is on
 * and the site is indexable; anything else is a 404. Never creates the key:
 * a guessed path must not mint one.
 */
class IndexNowKeyController
{
    public function __construct(
        private readonly IndexNowSettings $settings,
    ) {}

    public function __invoke(string $key): Response
    {
        if (! $this->settings->active() || ! hash_equals((string) $this->settings->storedKey(), $key)) {
            abort(404);
        }

        return response()->make($key, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
