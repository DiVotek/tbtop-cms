<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Tbtop\Cms\Api\ApiLocales;

/** `GET /locales`: the default locale and the ones the site shows, each of which publish needs a name in. */
final class LocalesController
{
    public function __invoke(ApiLocales $locales): JsonResponse
    {
        return response()->json(['default' => $locales->default(), 'active' => $locales->active()]);
    }
}
