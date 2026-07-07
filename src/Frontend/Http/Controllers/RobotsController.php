<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

class RobotsController
{
    public function __invoke(): Response
    {
        $sitemap = URL::to('sitemap.xml');

        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            "Sitemap: {$sitemap}",
            '',
        ]);

        return response()->make($body, 200, ['Content-Type' => 'text/plain']);
    }
}
