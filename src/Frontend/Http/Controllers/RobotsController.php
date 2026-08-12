<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Tbtop\Cms\Frontend\Seo\IndexingSetting;

class RobotsController
{
    public function __construct(
        private readonly IndexingSetting $indexing,
    ) {}

    public function __invoke(): Response
    {
        $lines = $this->indexing->enabled()
            ? $this->allowLines()
            : ['User-agent: *', 'Disallow: /'];

        return response()->make(implode("\n", [...$lines, '']), 200, ['Content-Type' => 'text/plain']);
    }

    /** @return list<string> */
    private function allowLines(): array
    {
        $lines = ['User-agent: *', 'Allow: /'];

        if (config('tbtop-cms.seo.sitemap.enabled', true)) {
            $lines[] = 'Sitemap: ' . URL::to('sitemap.xml');
        }

        return $lines;
    }
}
