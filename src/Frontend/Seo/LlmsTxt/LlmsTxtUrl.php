<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\LlmsTxt;

use Illuminate\Support\Facades\URL;
use Tbtop\Cms\Page\PageUrl;

/** Where a locale's llms.txt lives: `/llms.txt` for the default locale, `/uk/llms.txt` for another. */
final readonly class LlmsTxtUrl
{
    public function __construct(private PageUrl $url) {}

    public function for(string $locale): string
    {
        return URL::to($this->url->build('llms.txt', $locale));
    }
}
