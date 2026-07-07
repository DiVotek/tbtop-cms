<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Http\Controllers;

use Illuminate\Http\Response;
use Tbtop\Cms\Frontend\Seo\SitemapEntryBuilder;
use Tbtop\Cms\Frontend\Seo\SitemapXmlRenderer;

class SitemapController
{
    public function __construct(
        private readonly SitemapEntryBuilder $entries,
        private readonly SitemapXmlRenderer $renderer,
    ) {}

    public function __invoke(): Response
    {
        $xml = $this->renderer->render($this->entries->build());

        return response()->make($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
